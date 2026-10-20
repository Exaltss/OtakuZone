<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->enum('scope', ['global', 'page', 'genre'])->default('global');
            $table->json('condition_json');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'priority'], 'idx_fr_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_rules');
    }
};
