<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->json('studios')->nullable()->after('genres');
            $table->json('demographics')->nullable()->after('studios');
            $table->json('themes')->nullable()->after('demographics');
            $table->string('tagline', 500)->nullable()->after('synopsis');
            $table->string('age_rating', 10)->nullable()->after('is_adult');
            $table->json('networks')->nullable()->after('trailer_url');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['studios', 'demographics', 'themes', 'tagline', 'age_rating', 'networks']);
        });
    }
};
