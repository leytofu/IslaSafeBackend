<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evacuee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'age_recorded_at' => 'date', 'reported_age' => 'integer'];
    }

    public function resident()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function place()
    {
        return $this->belongsTo(EvacuationPlace::class);
    }

    public function registrations()
    {
        return $this->hasMany(EvacuationRegistration::class);
    }
}
