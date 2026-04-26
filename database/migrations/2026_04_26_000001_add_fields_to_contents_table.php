<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->json('alternative_names')->nullable()->after('name');
            $table->enum('status', ['ongoing', 'completed', 'hiatus', 'cancelled'])->default('ongoing')->after('type');
            $table->timestamp('last_unit_update')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['alternative_names', 'status', 'last_unit_update']);
        });
    }
};
