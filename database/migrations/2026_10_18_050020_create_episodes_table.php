<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->decimal('number', 5, 2);
            $table->string('title', 255)->nullable();
            $table->string('thumbnail_path', 500)->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->text('quality_urls')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['anime_id', 'number'], 'uq_ep');
            $table->index(['anime_id', 'is_hidden'], 'idx_ep_anime');
            $table->fullText('title', 'ft_ep');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
