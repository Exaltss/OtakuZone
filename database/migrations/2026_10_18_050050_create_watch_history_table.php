<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('episode_id')
                ->constrained('episodes')
                ->cascadeOnDelete();
            $table->unsignedInteger('position_sec')->default(0);
            $table->unsignedInteger('duration_sec')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'episode_id'], 'uq_hist');
            $table->index(['user_id', 'updated_at'], 'idx_hist_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history');
    }
};
