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
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_number', 32)->nullable()->after('role');
            $table->string('office')->nullable()->after('contact_number');
            $table->string('jurisdiction')->nullable()->after('office');
            $table->string('avatar')->nullable()->after('jurisdiction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contact_number', 'office', 'jurisdiction', 'avatar']);
        });
    }
};
