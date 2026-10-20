<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64);
            $table->integer('cat_id')->nullable();
            $table->string('title', 255);
            $table->string('slug', 260)->unique('uq_anime_slug');
            $table->string('image_path', 500)->nullable();
            $table->mediumText('synopsis')->nullable();
            $table->string('type', 40)->nullable();
            $table->string('status', 30)->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('season', 20)->nullable();
            $table->smallInteger('episodes_total')->nullable();
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('view_count')->nullable();
            $table->unsignedInteger('popularity_score')->default(0);
            $table->string('source', 20)->default('animeku');
            $table->boolean('is_hidden')->default(false);
            $table->json('raw_meta')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id'], 'uq_anime_external');
            $table->index(['is_hidden', 'status', 'type', 'popularity_score'], 'idx_anime_browse');
            $table->index('cat_id', 'idx_anime_cat');
            $table->fullText(['title', 'synopsis'], 'ft_anime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anime');
    }
};
