<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom agregat vote komunitas (terpisah dari rating sumber/MAL).
        Schema::table('anime', function (Blueprint $table): void {
            $table->unsignedInteger('vote_count')->default(0)->after('popularity_score');
            $table->decimal('vote_avg', 3, 2)->nullable()->after('vote_count');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->dropColumn(['vote_count', 'vote_avg']);
        });
    }
};
