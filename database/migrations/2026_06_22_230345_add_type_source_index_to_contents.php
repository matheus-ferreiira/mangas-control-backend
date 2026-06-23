<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice composto (type, source) para as queries de dedup/filtragem que combinam
 * os dois campos. `idx_contents_type` (type isolado) já existe de migration anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->index(['type', 'source'], 'idx_contents_type_source');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('idx_contents_type_source');
        });
    }
};
