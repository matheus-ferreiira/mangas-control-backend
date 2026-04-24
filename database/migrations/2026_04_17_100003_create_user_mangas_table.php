<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_contents');
        Schema::create('user_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('current_units')->default(0);
            $table->decimal('rating', 3, 1)->nullable();
            $table->enum('status', ['reading', 'completed', 'paused', 'dropped', 'plan_to_read'])->default('plan_to_read');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contents');
    }
};
