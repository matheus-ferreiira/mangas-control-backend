<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->float('rating')->nullable()->after('status');
            $table->unsignedInteger('popularity')->nullable()->after('rating');
            $table->unsignedInteger('votes_count')->nullable()->after('popularity');
            $table->text('synopsis')->nullable()->after('votes_count');
            $table->json('genres')->nullable()->after('synopsis');
            $table->unsignedSmallInteger('release_year')->nullable()->after('genres');
            $table->string('original_language', 10)->nullable()->after('release_year');
            $table->string('background')->nullable()->after('original_language');

            $table->index('type', 'idx_contents_type');
            $table->index('rating', 'idx_contents_rating');
            $table->index('popularity', 'idx_contents_popularity');
            $table->index('release_year', 'idx_contents_release_year');
            $table->index('name', 'idx_contents_name');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('idx_contents_type');
            $table->dropIndex('idx_contents_rating');
            $table->dropIndex('idx_contents_popularity');
            $table->dropIndex('idx_contents_release_year');
            $table->dropIndex('idx_contents_name');

            $table->dropColumn([
                'rating', 'popularity', 'votes_count', 'synopsis',
                'genres', 'release_year', 'original_language', 'background',
            ]);
        });
    }
};
