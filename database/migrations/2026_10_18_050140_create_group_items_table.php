<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                ->constrained('groups')
                ->cascadeOnDelete();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['group_id', 'anime_id'], 'uq_gi');
            $table->index('anime_id', 'idx_gi_anime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_items');
    }
};
