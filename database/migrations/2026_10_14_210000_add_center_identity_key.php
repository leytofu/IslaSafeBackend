<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->string('canonical_key', 64)->nullable()->unique();
        });
        // Preserve historical duplicate records and their registrations. Reserve
        // each identity on the oldest record so new duplicates cannot be created.
        $seen = [];
        DB::table('evacuation_centers')->orderBy('id')->chunkById(250, function ($rows) use (&$seen) {
            foreach ($rows as $row) {
                $name = preg_replace('/\s+/u', ' ', trim($row->name));
                $key = hash('sha256', $row->place_id.'|'.mb_strtolower($name));
                if (! isset($seen[$key])) {
                    DB::table('evacuation_centers')->where('id', $row->id)->update(['canonical_key' => $key]);
                    $seen[$key] = true;
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->dropUnique(['canonical_key']);
            $table->dropColumn('canonical_key');
        });
    }
};
