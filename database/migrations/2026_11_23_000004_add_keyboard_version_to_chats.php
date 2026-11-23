<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // Bottom keyboard the user has; another value means it must be sent again.
            $table->string('keyboard_version', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('keyboard_version'));
    }
};
