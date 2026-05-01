<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->foreignId('user_site_id')
                ->nullable()
                ->after('site_id')
                ->constrained('user_sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->dropForeign(['user_site_id']);
            $table->dropColumn('user_site_id');
        });
    }
};
