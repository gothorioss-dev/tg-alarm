<?php

namespace App\Console\Commands;

use App\Models\ChannelPost;
use App\Models\City;
use Illuminate\Console\Command;

class RetryPost extends Command
{
    protected $signature = 'app:retry-post {ids* : numbers of failed posts of the channel} {--city=zaporizhzhia}';

    protected $description = 'Process failed posts about outages again, from the first attempt';

    public function handle(): int
    {
        $city = City::query()->where('code', $this->option('city'))->first();

        if ($city === null) {
            $this->error("Unknown city {$this->option('city')}");

            return self::FAILURE;
        }

        $result = self::SUCCESS;

        foreach ($this->argument('ids') as $id) {
            $post = ChannelPost::query()->where('city_id', $city->id)->where('message_id', (int) $id)->first();

            if ($post?->status !== ChannelPost::FAILED) {
                $this->error("Post {$id} is ".($post === null ? 'unknown' : "{$post->status}, not failed"));
                $result = self::FAILURE;

                continue;
            }

            $post->update(['status' => ChannelPost::PENDING, 'attempts' => 0, 'available_at' => now()]);
            $this->info("Post {$id} is queued");
        }

        return $result;
    }
}
