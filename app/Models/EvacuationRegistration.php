<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationRegistration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'checked_out_at' => 'datetime', 'age' => 'integer'];
    }

    public function evacuee()
    {
        return $this->belongsTo(Evacuee::class);
    }

    public function event()
    {
        return $this->belongsTo(EvacuationEvent::class, 'event_id');
    }

    public function center()
    {
        return $this->belongsTo(EvacuationCenter::class, 'center_id');
    }

    public function initialCenter()
    {
        return $this->belongsTo(EvacuationCenter::class, 'initial_center_id');
    }

    public function place()
    {
        return $this->belongsTo(EvacuationPlace::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'camp_manager_id');
    }

    public function movements()
    {
        return $this->hasMany(EvacuationMovement::class, 'registration_id');
    }
}
