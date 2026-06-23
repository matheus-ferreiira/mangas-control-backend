<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toggle global de conteúdo adulto, vinculado ao perfil do usuário.
 * Default false → conteúdo +18 escondido em todas as listagens até o usuário optar por ver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_adult_content')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_adult_content');
        });
    }
};
