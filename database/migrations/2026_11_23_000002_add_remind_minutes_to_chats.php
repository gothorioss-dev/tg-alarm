<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // Null = reminders are off.
            $table->unsignedSmallInteger('remind_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('remind_minutes'));
    }
};
