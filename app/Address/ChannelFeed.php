<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\ChannelPost;
use App\Models\City;
use App\Models\CityEvent;
use App\Monitor\ProcessStatus;
use App\Sources\SourceException;
use App\Telegram\PostFile;
use App\Telegram\TelegramException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Outages from a public channel: a post with a file opens an outage, replies to it change and close it. */
final class ChannelFeed implements OutageFeed
{
    private const MAX_PAGES = 15;

    /** Replies to replies. */
    private const MAX_DEPTH = 10;

    private const RESTORED = '/^(?:Шановні споживачі\W*)?(?:Увага\W*)?Електропостачання\s+(?:повністю\s+)?відновлено(?:\s+повністю)?\W*$/ui';

    private const PARTIAL = '/Електропостачання\s+(?:частково\s+відновлено|відновлено\s+частково)/ui';

    private const EXTENDED = '/продовжено\s+до\s+(\d{1,2})[-:.](\d{2})\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/ui';

    private int $downloads = 0;

    public function __construct(
        private string $channel,
        private string $files,
        private ChannelReader $reader,
        private PostFile $postFile,
        private OutageFile $outageFile,
        private StreetResolver $streets,
        private AddressTracker $tracker,
        private Outbox $outbox,
        private ProcessStatus $status,
    ) {}

    public function poll(City $city): void
    {
        $this->read($city);
        $this->downloads = 0;

        $ids = ChannelPost::query()->where('city_id', $city->id)->where('status', ChannelPost::PENDING)
            ->where('available_at', '<=', now())->orderBy('message_id')->pluck('id');

        foreach ($ids as $id) {
            $post = ChannelPost::query()->find($id);

            if ($post?->status === ChannelPost::PENDING) {
                $this->process($city, $post);
                $this->status->beat();
            }
            // The rest waits for the next cycle.
            if ($this->downloads >= (int) config('addresses.files.per_cycle')) {
                return;
            }
        }
    }

    private function read(City $city): void
    {
        $last = ChannelPost::query()->where('city_id', $city->id)->max('message_id');
        $since = now()->subDays((int) config('addresses.history_days'));
        $posts = $page = $this->reader->page($this->channel);

        if ($posts === []) {
            throw new SourceException('Channel page format is not recognized');
        }

        for ($i = 1; $i < self::MAX_PAGES && $page !== []; $i++) {
            $oldest = reset($page);

            if ($last !== null ? $oldest['message_id'] <= $last + 1 : $oldest['published_at'] < $since) {
                break;
            }

            $page = $this->reader->page($this->channel, $oldest['message_id']);
            $posts += $page;
            $this->status->beat();
        }

        ksort($posts);

        if ($last === null) {
            $posts = array_filter($posts, fn (array $post) => $post['published_at'] >= $since);
        }

        DB::transaction(function () use ($city, $posts, $last) {
            $known = ChannelPost::query()->where('city_id', $city->id)->whereIn('message_id', array_keys($posts))->get()->keyBy('message_id');

            foreach ($posts as $id => $post) {
                $existing = $known->get($id);

                if ($existing === null) {
                    // Older posts of the page are known only as history.
                    $this->store($city, $post, $last === null || $id < $last);
                } elseif ($existing->hash !== $this->hash($post)) {
                    // The post has been edited.
                    $existing->update([
                        'text' => $post['text'], 'file' => $post['file'], 'hash' => $this->hash($post),
                        'status' => $this->initialStatus($post), 'attempts' => 0, 'available_at' => now(),
                    ]);
                }
            }
        });
    }

    /** @param array{message_id: int, reply_to: ?int, text: string, file: ?string, published_at: CarbonImmutable} $post */
    private function store(City $city, array $post, bool $silent): ChannelPost
    {
        return ChannelPost::query()->create([
            ...$post, 'city_id' => $city->id, 'hash' => $this->hash($post), 'status' => $this->initialStatus($post),
            'silent' => $silent, 'available_at' => now(),
        ]);
    }

    /** @param array{reply_to: ?int, text: string, file: ?string} $post */
    private function initialStatus(array $post): string
    {
        $relevant = $post['file'] === null ? $post['reply_to'] !== null : preg_match($this->files, $post['file']) === 1;

        return $relevant ? ChannelPost::PENDING : ChannelPost::SKIPPED;
    }

    /** @param array{reply_to: ?int, text: string, file: ?string} $post */
    private function hash(array $post): string
    {
        return hash('sha256', json_encode([$post['text'], $post['file']], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function process(City $city, ChannelPost $post, int $depth = 0): void
    {
        try {
            $post->reply_to === null ? $this->outage($city, $post) : $this->reply($city, $post, $depth);
        } catch (Throwable $e) {
            // One post must not hold up the others.
            $this->failed($city, $post, $e);
        }
    }

    private function failed(City $city, ChannelPost $post, Throwable $e): void
    {
        Log::warning("Post {$post->message_id} of {$this->channel} failed: {$e->getMessage()}");

        // Telegram asks to wait: it is not a problem of the post.
        if ($e instanceof TelegramException && $e->getCode() === 429) {
            $post->update(['available_at' => now()->addSeconds(max(1, $e->retryAfter()))]);

            return;
        }

        $attempts = $post->attempts + 1;
        $exhausted = $attempts >= (int) config('addresses.files.attempts');
        $post->update([
            'attempts' => $attempts,
            'available_at' => now()->addMinutes(2 ** ($attempts - 1)),
            'status' => $exhausted ? ChannelPost::FAILED : ChannelPost::PENDING,
        ]);

        if ($exhausted) {
            $this->outbox->admin("post-failed:{$city->code}:{$post->message_id}:".substr($post->hash, 0, 8),
                "Не вдалося обробити пост про відключення, людей за ним не сповіщено: https://t.me/{$this->channel}/{$post->message_id}\n{$e->getMessage()}");
        }
    }

    private function outage(City $city, ChannelPost $post): void
    {
        // Nothing is left to follow.
        if ($post->silent && $post->event === null && $this->isRestored($city, $post->message_id)) {
            DB::transaction(function () use ($city, $post) {
                $post->update(['status' => ChannelPost::SKIPPED]);

                // Its announcement is over too.
                foreach (CityEvent::query()->where('city_id', $city->id)->where('key', $this->key((string) $post->file))->whereNull('closed_at')->get() as $event) {
                    $event->update(['closed_at' => now()]);
                    $this->tracker->apply($event, false);
                }
            });

            return;
        }

        $rows = $this->rows($post);
        $start = min(array_map(fn (FileRow $row) => $row->startsAt, $rows));
        $announced = preg_match('/буде\s+знеструмлено/ui', $post->text) === 1 || $start > $post->published_at->addMinutes(5);
        $key = $this->key((string) $post->file);

        DB::transaction(function () use ($city, $post, $rows, $announced, $start, $key) {
            // The write goes first: it locks the database.
            $post->update(['status' => ChannelPost::DONE]);
            $event = $post->event ?? CityEvent::query()->where('city_id', $city->id)->where('key', $key)->whereNull('closed_at')->latest('id')->first()
                ?? new CityEvent(['city_id' => $city->id, 'key' => $key]);

            // An edited announcement says nothing new after the start or a reply.
            if ($event->exists && ChannelPost::query()->where('city_event_id', $event->id)->where('message_id', '>', $post->message_id)->where('status', ChannelPost::DONE)->exists()) {
                $post->update(['city_event_id' => $event->id]);

                return;
            }

            $started = $event->exists && $event->announced && ! $announced;
            $event->announced = $announced;
            $this->saveRows($city, $event, $rows);
            $post->update(['city_event_id' => $event->id]);
            $this->tracker->apply($event, ! $post->silent, $started, $start <= now() && $this->isLate($post));
        });
    }

    private function reply(City $city, ChannelPost $post, int $depth): void
    {
        $isRestored = $post->file === null && preg_match(self::RESTORED, $post->text) === 1;
        $parent = $this->parent($city, $post, $isRestored, $depth);

        if ($parent?->status === ChannelPost::PENDING) {
            $post->update(['available_at' => now()->addMinute()]);

            return;
        }

        $event = $parent?->event;

        if ($event === null) {
            $post->update(['status' => ChannelPost::SKIPPED]);

            return;
        }

        $isPartial = $post->file !== null && preg_match(self::PARTIAL, $post->text) === 1;
        $end = $post->file === null && preg_match(self::EXTENDED, $post->text, $match) === 1 ? $this->end($match) : null;

        if (! $isRestored && ! $isPartial && $end === null) {
            $post->update(['status' => ChannelPost::SKIPPED, 'city_event_id' => $event->id]);

            if (! $post->silent) {
                $this->outbox->admin("post-unknown:{$city->code}:{$post->message_id}:".substr($post->hash, 0, 8),
                    "Незрозуміла відповідь на пост про відключення: https://t.me/{$this->channel}/{$post->message_id}\n".mb_strimwidth($post->text, 0, 500, '…'));
            }

            return;
        }

        $rows = $isPartial ? $this->rows($post) : [];

        DB::transaction(function () use ($city, $post, $event, $isRestored, $rows, $end) {
            $post->update(['status' => ChannelPost::DONE, 'city_event_id' => $event->id]);

            // An edited reply says nothing new after the next one.
            if (ChannelPost::query()->where('city_event_id', $event->id)->where('message_id', '>', $post->message_id)->where('status', ChannelPost::DONE)->exists()) {
                return;
            }

            $started = $event->announced;

            if ($isRestored) {
                $event->update(['closed_at' => now()]);
            } else {
                $event->fill(['announced' => false, 'closed_at' => null]);
                $rows !== [] ? $this->saveRows($city, $event, $rows) : $this->extend($event, $end);
            }

            $this->tracker->apply($event, ! $post->silent, $started, $this->isLate($post));
        });
    }

    /** The post that is answered; an old one is read from the channel. */
    private function parent(City $city, ChannelPost $post, bool $isRestored, int $depth): ?ChannelPost
    {
        $find = fn () => ChannelPost::query()->where('city_id', $city->id)->where('message_id', $post->reply_to)->first();
        $parent = $find();

        if ($parent === null) {
            // Nobody has been told about an outage that is not known.
            if ($isRestored || $depth >= self::MAX_DEPTH || $post->reply_to >= $post->message_id) {
                return null;
            }

            $found = $this->reader->page($this->channel, $post->reply_to + 1)[$post->reply_to] ?? null;

            if ($found === null) {
                return null;
            }

            $parent = $this->store($city, $found, true);
        }

        if ($parent->status === ChannelPost::PENDING && $parent->available_at <= now() && $depth < self::MAX_DEPTH) {
            $this->process($city, $parent, $depth + 1);
            $parent = $find();
        }

        return $parent;
    }

    private function isRestored(City $city, int $messageId, int $depth = 0): bool
    {
        $replies = ChannelPost::query()->where('city_id', $city->id)->where('reply_to', $messageId)->get();

        foreach ($replies as $reply) {
            if (($reply->file === null && preg_match(self::RESTORED, $reply->text) === 1) || ($depth < self::MAX_DEPTH && $this->isRestored($city, $reply->message_id, $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-list<FileRow> */
    private function rows(ChannelPost $post): array
    {
        $this->downloads++;
        // A long run is not a hang.
        $this->status->beat();
        $path = $this->postFile->download($this->channel, $post->message_id, (string) $post->file, (int) config('addresses.files.max_size'));

        try {
            return $this->outageFile->read($path);
        } finally {
            @unlink($path);
        }
    }

    /** @param non-empty-list<FileRow> $rows */
    private function saveRows(City $city, CityEvent $event, array $rows): void
    {
        $ends = array_map(fn (FileRow $row) => $row->endsAt ?? $row->startsAt, $rows);
        $event->ends_at = max($ends);
        $event->save();
        $event->rows()->delete();
        $streets = [];

        foreach ($rows as $row) {
            $streets[$row->street] ??= $this->streets->resolve($city, $row->street)->id;
            $event->rows()->create([
                'street_id' => $streets[$row->street], 'kind' => $row->kind->value, 'houses' => $row->houses,
                'starts_at' => $row->startsAt, 'ends_at' => $row->endsAt,
            ]);

            foreach ((new HouseList($row->houses))->unknown() as $part) {
                Log::info("House is not recognized: {$row->street}: {$part}");
            }
        }
    }

    private function extend(CityEvent $event, CarbonImmutable $end): void
    {
        $event->ends_at = $end;
        $event->save();
        $event->rows()->where('starts_at', '<', $end)->update(['ends_at' => $end]);
    }

    /** @param array<int, string> $match */
    private function end(array $match): ?CarbonImmutable
    {
        [, $hour, $minute, $day, $month, $year] = array_map(intval(...), $match);

        return checkdate($month, $day, $year) && $hour <= 24 && $minute <= 59
            ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('telegram.timezone'))->setTime($hour, $minute)->utc()
            : null;
    }

    /** The name of a file is the number of an outage. */
    private function key(string $file): string
    {
        return mb_strtolower((string) preg_replace('/[\s_]*(?:\(\d+\))?\.xlsx$/ui', '', $file));
    }

    private function isLate(ChannelPost $post): bool
    {
        return $post->published_at < now()->subHours((int) config('addresses.late_hours'));
    }
}
