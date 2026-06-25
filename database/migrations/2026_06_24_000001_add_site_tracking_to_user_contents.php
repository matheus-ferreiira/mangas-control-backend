<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            // Título da obra como aparece no site de leitura (preenchido pelo usuário)
            $table->string('site_title')->nullable()->after('user_site_id');
            // Último capítulo disponível encontrado pelo scheduler no site
            $table->string('site_last_chapter')->nullable()->after('site_title');
        });
    }

    public function down(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->dropColumn(['site_title', 'site_last_chapter']);
        });
    }
};
