<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('password_reset_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('challenge_id', 64)->unique()->index();
            $table->string('otp_hash');
            $table->timestamp('otp_expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('resend_available_at')->nullable();
            $table->enum('status', ['pending', 'verified', 'expired', 'consumed'])->default('pending');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_challenges');
    }
};
