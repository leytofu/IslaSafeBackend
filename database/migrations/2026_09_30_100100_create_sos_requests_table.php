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
        Schema::create('sos_requests', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name');
            $table->string('contact', 32);
            $table->string('location');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('type');
            $table->string('category', 32);
            $table->string('priority', 16);
            $table->text('description');
            $table->string('status', 16)->default('Pending')->index();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sos_requests');
    }
};
