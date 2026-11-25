<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('polled_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('streets_polled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('streets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            // Exactly as in the source.
            $table->string('name');
            $table->string('key');
            $table->boolean('active')->default(true);
            // False for streets known only from outages.
            $table->boolean('listed')->default(true);
            $table->json('houses')->nullable();
            $table->timestamp('houses_at')->nullable();
            $table->unique(['city_id', 'key']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained();
            $table->foreignId('street_id')->constrained();
            $table->string('house');
            $table->string('house_key');
            // Null when the list of houses was not available.
            $table->boolean('house_known')->nullable();
            $table->timestamps();
            $table->unique(['chat_id', 'street_id', 'house_key']);
            $table->index('street_id');
        });

        Schema::create('city_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            // The same for an announcement and for the actual start.
            $table->string('key');
            // Announced, but the start has not been confirmed.
            $table->boolean('announced')->default(false);
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['city_id', 'key']);
            $table->index('closed_at');
        });

        Schema::create('city_event_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('street_id')->constrained();
            $table->string('kind');
            $table->text('houses');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->index('street_id');
        });

        Schema::create('channel_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->text('text');
            $table->string('file')->nullable();
            $table->string('hash', 64);
            $table->string('status');
            // Read for the first time as history: nobody is notified.
            $table->boolean('silent')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at');
            $table->foreignId('city_event_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['city_id', 'message_id']);
            $table->index(['city_id', 'status']);
            $table->index('reply_to');
        });

        // Outages at the addresses of users.
        Schema::create('address_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_event_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->boolean('announced')->default(false);
            $table->unsignedInteger('revision')->default(1);
            // When the current start became known.
            $table->timestamp('timed_at');
            $table->timestamp('reminded_day_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['address_id', 'city_event_id']);
            $table->index(['ended_at', 'starts_at']);
        });

        Schema::table('chats', fn (Blueprint $table) => $table->json('address_draft')->nullable());
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('address_draft'));

        foreach (['address_events', 'channel_posts', 'city_event_rows', 'city_events', 'addresses', 'streets', 'cities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
