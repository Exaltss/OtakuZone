<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->enum('status', [
                'watching',
                'completed',
                'on_hold',
                'dropped',
                'plan_to_watch',
            ])->default('plan_to_watch');
            $table->timestamps();

            $table->unique(['user_id', 'anime_id'], 'uq_wl');
            $table->index(['user_id', 'status'], 'idx_wl_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
    }
};
