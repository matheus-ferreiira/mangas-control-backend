<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('releases_api_url')->nullable()->after('url');
            $table->string('releases_api_type')->nullable()->default('json')->after('releases_api_url');
            $table->string('releases_title_field')->nullable()->default('alternativeTitle')->after('releases_api_type');
            $table->string('releases_chapter_field')->nullable()->default('recentChapters.0.number')->after('releases_title_field');
        });

        // Popula os sites padrão também em produção (o deploy roda migrate, não seed).
        Artisan::call('db:seed', ['--class' => 'SitesSeeder', '--force' => true]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'releases_api_url',
                'releases_api_type',
                'releases_title_field',
                'releases_chapter_field',
            ]);
        });
    }
};
