<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20);
            $table->string('message', 500);
            $table->json('context_json')->nullable();
            $table->char('fingerprint', 64)->unique('uq_ee_fp');
            $table->unsignedInteger('count')->default(1);
            $table->dateTime('first_at')->nullable();
            $table->dateTime('last_at')->nullable();

            $table->index('last_at', 'idx_ee_last');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
