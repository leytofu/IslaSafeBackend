<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('google_subject')->nullable()->unique();
            $table->string('barangay', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamp('resident_verified_at')->nullable();
        });
        Schema::create('google_auth_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nonce', 64)->unique();
            $table->string('purpose', 20);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_auth_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_subject']);
            $table->dropColumn(['google_subject', 'barangay', 'address', 'resident_verified_at']);
        });
        // Keep passwords nullable: Google-only accounts have no local password.
    }
};
