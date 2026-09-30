<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            // ID estável da obra no site de leitura (ex.: ToonLivre "obra-1eaae2fd").
            // Preenchido no 1º vínculo do sync de capítulos; depois o match é por ele.
            $table->string('site_work_id', 64)->nullable()->after('site_last_chapter')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->dropIndex(['site_work_id']);
            $table->dropColumn('site_work_id');
        });
    }
};
