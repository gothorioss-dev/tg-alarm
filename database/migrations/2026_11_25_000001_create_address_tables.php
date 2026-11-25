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
            // Responses in a row that are suspiciously short.
            $table->unsignedInteger('shrunk')->default(0);
            $table->string('snapshot_hash', 64)->nullable();
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
            $table->index('city_id');
        });

        // The last response of the source.
        Schema::create('city_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('street_key');
            $table->string('kind');
            $table->text('houses');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->index(['city_id', 'street_key']);
        });

        // Outages at the addresses of users.
        Schema::create('address_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('missing')->default(0);
            // When the current start became known.
            $table->timestamp('timed_at');
            $table->timestamp('reminded_day_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['address_id', 'kind', 'starts_at']);
            $table->index(['ended_at', 'starts_at']);
        });

        Schema::table('chats', fn (Blueprint $table) => $table->json('address_draft')->nullable());
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('address_draft'));

        foreach (['address_events', 'city_outages', 'addresses', 'streets', 'cities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
