<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->mediumText('review_text')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'anime_id'], 'uq_rate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
