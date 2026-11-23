<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An edited post may return to its earlier content, which is a new version.
        Schema::table('publications', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id', 'content_hash']);
            $table->index(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('publications', fn (Blueprint $table) => $table->dropIndex(['source', 'external_id']));
    }
};
