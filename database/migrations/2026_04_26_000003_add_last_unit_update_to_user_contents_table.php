<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->timestamp('last_unit_update')->nullable()->after('current_units');
        });
    }

    public function down(): void
    {
        Schema::table('user_contents', function (Blueprint $table) {
            $table->dropColumn('last_unit_update');
        });
    }
};
