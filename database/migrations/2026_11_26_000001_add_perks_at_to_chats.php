<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // When the perks of the first users were given; they are kept after leaving.
            $table->timestamp('perks_at')->nullable();
        });

        // Everyone who has selected a queue so far helped with testing.
        DB::table('chats')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('subscriptions')->whereColumn('subscriptions.chat_id', 'chats.id'))
            ->update(['perks_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('perks_at'));
    }
};
