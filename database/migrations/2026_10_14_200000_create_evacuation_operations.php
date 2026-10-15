<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evacuation_places', function (Blueprint $table) {
            $table->id();
            $table->string('barangay', 120);
            $table->string('municipality', 120);
            $table->string('canonical_key', 255)->unique();
            $table->timestamps();
        });
        Schema::create('evacuation_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->foreignId('place_id')->constrained('evacuation_places')->restrictOnDelete();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('Operational');
            $table->string('contact', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
        Schema::create('evacuation_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('status')->default('active');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
        Schema::create('evacuation_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('active_event_id')->nullable()->constrained('evacuation_events')->restrictOnDelete();
        });
        DB::table('evacuation_state')->insert(['id' => 1, 'active_event_id' => null]);
        Schema::create('camp_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('center_id')->constrained('evacuation_centers')->restrictOnDelete();
            $table->json('place_ids');
            $table->timestamps();
        });
        Schema::create('evacuees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('full_name', 180)->index();
            $table->date('date_of_birth')->nullable();
            $table->unsignedTinyInteger('reported_age')->nullable();
            $table->date('age_recorded_at')->nullable();
            $table->string('gender', 32)->default('unspecified');
            $table->foreignId('place_id')->constrained('evacuation_places')->restrictOnDelete();
            $table->string('purok', 120)->nullable();
            $table->timestamps();
            $table->index(['place_id', 'full_name']);
        });
        Schema::create('evacuation_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_request_id')->unique();
            $table->foreignId('evacuee_id')->constrained('evacuees')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('evacuation_events')->restrictOnDelete();
            $table->foreignId('center_id')->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('initial_center_id')->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('camp_manager_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('registered_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->string('status')->default('checked_in');
            $table->string('full_name', 180);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('age_group', 32);
            $table->string('gender', 32);
            $table->foreignId('place_id')->constrained('evacuation_places')->restrictOnDelete();
            $table->string('purok', 120)->nullable();
            $table->timestamps();
            $table->unique(['evacuee_id', 'event_id']);
            $table->index(['center_id', 'event_id', 'status']);
        });
        Schema::create('evacuation_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('evacuation_registrations')->restrictOnDelete();
            $table->foreignId('from_center_id')->nullable()->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('to_center_id')->nullable()->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->timestamp('occurred_at');
            $table->index(['to_center_id', 'occurred_at']);
            $table->index(['from_center_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['evacuation_movements', 'evacuation_registrations', 'evacuees', 'camp_assignments', 'evacuation_state', 'evacuation_events', 'evacuation_centers', 'evacuation_places'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
