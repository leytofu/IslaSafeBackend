<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['place_ids' => 'array'];
    }

    public function center()
    {
        return $this->belongsTo(EvacuationCenter::class, 'center_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
