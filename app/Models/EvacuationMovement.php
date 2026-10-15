<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationMovement extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function registration()
    {
        return $this->belongsTo(EvacuationRegistration::class, 'registration_id');
    }
}
