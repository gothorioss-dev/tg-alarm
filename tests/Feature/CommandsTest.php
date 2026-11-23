<?php

namespace Tests\Feature;

use App\Models\SourceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_requires_a_poll_after_the_given_time(): void
    {
        $this->artisan('app:health')->assertFailed();
        SourceState::query()->create(['source' => 'site', 'polled_at' => now()]);
        $this->artisan('app:health', ['--since' => now()->subMinute()->timestamp])->assertSuccessful();
        $this->artisan('app:health', ['--since' => now()->addMinute()->timestamp])->assertFailed();
    }

    public function test_notify_sends_escaped_text_to_admin(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        $this->artisan('app:notify', ['text' => 'a < b'])->assertSuccessful();
        Http::assertSent(fn ($r) => $r['chat_id'] === 999 && str_contains($r['text'], 'a &lt; b'));
    }
}
