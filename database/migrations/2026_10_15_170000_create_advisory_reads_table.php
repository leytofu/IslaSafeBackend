<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('advisories', function (Blueprint $table) { $table->unsignedInteger('notification_version')->default(1); });
        Schema::create('advisory_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisory_id')->constrained()->cascadeOnDelete();
            $table->string('version', 64);
            $table->timestamp('read_at');
            $table->unique(['user_id', 'advisory_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('advisory_reads');
        Schema::table('advisories', function (Blueprint $table) { $table->dropColumn('notification_version'); });
    }
};
