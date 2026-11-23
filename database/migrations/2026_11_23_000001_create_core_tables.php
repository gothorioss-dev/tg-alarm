<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id')->unique();
            $table->string('type');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->string('queue', 3);
            $table->unique(['chat_id', 'queue']);
            $table->index('queue');
        });

        Schema::create('source_states', function (Blueprint $table) {
            $table->string('source')->primary();
            $table->json('state')->nullable();
            $table->boolean('initialized')->default(false);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('polled_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
        });

        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('content_hash', 64);
            $table->string('kind');
            $table->string('status');
            $table->date('schedule_date')->nullable();
            $table->string('schedule_hash', 64)->nullable();
            $table->json('queues')->nullable();
            $table->text('title');
            $table->text('url');
            $table->timestamp('declared_at')->nullable();
            $table->timestamp('published_at');
            // Compare with published_at to see how far each source lags.
            $table->timestamp('fetched_at');
            $table->foreignId('twin_id')->nullable();
            $table->unique(['source', 'external_id', 'content_hash']);
            $table->index(['schedule_date', 'schedule_hash']);
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->foreignId('publication_id')->constrained();
            // Highest-priority source that published or confirmed this version.
            $table->string('source');
            $table->timestamp('declared_at')->nullable();
            $table->json('queues');
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id');
            $table->string('dedupe_key')->unique();
            $table->text('text');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        foreach (['deliveries', 'schedules', 'publications', 'source_states', 'subscriptions', 'chats'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
