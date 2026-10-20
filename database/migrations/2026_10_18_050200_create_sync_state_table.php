<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('job_key', 120)->unique('uq_ss_job');
            $table->integer('last_page_ok')->default(0);
            $table->string('cursor', 255)->nullable();
            $table->enum('last_status', ['ok', 'partial', 'fail'])->default('ok');
            $table->dateTime('last_run_at')->nullable();
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};
