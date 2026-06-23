<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration C — Tabela de auditoria de mudanças detectadas pelo content:sync-updates.
 * Registra cada alteração em campo crítico de um conteúdo vinculado a usuário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_updates_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('field_changed', 50); // ex: total_units, status, total_seasons
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 20); // anilist / jikan / tmdb
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('content_id', 'idx_updates_log_content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_updates_log');
    }
};
