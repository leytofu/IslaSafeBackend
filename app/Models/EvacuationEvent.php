<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function registrations()
    {
        return $this->hasMany(EvacuationRegistration::class, 'event_id');
    }
}
