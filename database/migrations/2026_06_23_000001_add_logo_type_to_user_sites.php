<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sites', function (Blueprint $table) {
            // URL deixa de ser obrigatória (apps como Crunchyroll/Netflix não têm URL relevante)
            $table->string('url', 500)->nullable()->change();
            $table->string('logo_url', 500)->nullable()->after('url');
            $table->enum('type', ['website', 'app'])->default('website')->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('user_sites', function (Blueprint $table) {
            $table->dropColumn(['logo_url', 'type']);
            // url mantida nullable no down para evitar falha caso existam registros sem URL
        });
    }
};
