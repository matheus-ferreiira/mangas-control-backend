<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de leitura por item da biblioteca. `{n}` vira o próximo capítulo
 * (current_units + 1) no app, ex.: https://site.com/obra/{n}/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->string('chapter_url', 500)->nullable()->after('site_work_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->dropColumn('chapter_url');
        });
    }
};
