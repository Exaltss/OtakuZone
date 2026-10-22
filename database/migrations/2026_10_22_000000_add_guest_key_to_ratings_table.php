<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('guest_key', 64)->nullable()->after('user_id');
            $table->index('guest_key', 'idx_rate_guest');
            $table->unique(['guest_key', 'anime_id'], 'uq_rate_guest');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table): void {
            $table->dropUnique('uq_rate_guest');
            $table->dropIndex('idx_rate_guest');
            $table->dropColumn('guest_key');
        });
    }
};
