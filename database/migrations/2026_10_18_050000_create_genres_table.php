<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique('uq_genres_slug');
            $table->string('external_key', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genres');
    }
};
