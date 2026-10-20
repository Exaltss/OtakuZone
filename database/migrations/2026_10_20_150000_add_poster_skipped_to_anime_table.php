<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->timestamp('poster_skipped_at')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->dropColumn('poster_skipped_at');
        });
    }
};
