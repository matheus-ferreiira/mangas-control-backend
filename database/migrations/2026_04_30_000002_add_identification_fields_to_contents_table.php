<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Identificação externa — permite dedup preciso e links para fontes
            $table->string('external_id')->nullable()->after('id');
            $table->enum('source', ['jikan', 'tmdb'])->nullable()->after('external_id');

            // Score interno: rating × log10(votes + 1) — ranking fonte-agnóstico
            $table->float('score')->nullable()->after('votes_count');

            // Metadados adicionais
            $table->boolean('is_adult')->default(false)->after('background');
            $table->unsignedSmallInteger('duration')->nullable()->after('is_adult'); // minutos
            $table->string('trailer_url')->nullable()->after('duration');
            $table->string('country', 10)->nullable()->after('original_language');

            // Índice composto para dedup rápido por fonte
            $table->index(['source', 'external_id'], 'idx_contents_source_external');
            $table->index('score', 'idx_contents_score');
            $table->index('is_adult', 'idx_contents_is_adult');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('idx_contents_source_external');
            $table->dropIndex('idx_contents_score');
            $table->dropIndex('idx_contents_is_adult');

            $table->dropColumn([
                'external_id', 'source', 'score',
                'is_adult', 'duration', 'trailer_url', 'country',
            ]);
        });
    }
};
