<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mangas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manga_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->integer('current_chapters')->default(0);
            $table->decimal('rating', 3, 1)->nullable();
            $table->enum('status', ['reading', 'completed', 'paused', 'dropped', 'plan_to_read'])->default('plan_to_read');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mangas');
    }
};
