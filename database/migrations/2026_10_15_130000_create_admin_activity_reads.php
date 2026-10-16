<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activity_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('activity_key', 64);
            $table->timestamp('read_at');
            $table->unique(['user_id', 'activity_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_reads');
    }
};
