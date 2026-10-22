<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indeks covering untuk agregat MAX(synced_at) GROUP BY anime_id
        // yang dipakai query "Rilis terbaru" di beranda. Memungkinkan loose
        // index scan sehingga beranda tetap cepat saat episode sudah puluhan ribu.
        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(['anime_id', 'synced_at'], 'idx_ep_anime_synced');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('idx_ep_anime_synced');
        });
    }
};
