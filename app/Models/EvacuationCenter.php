<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationCenter extends Model
{
    protected $guarded = [];

    protected $hidden = ['canonical_key'];

    public static function normalizedName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name));
    }

    public static function identityKey(string $name, int $placeId): string
    {
        return hash('sha256', $placeId.'|'.mb_strtolower(static::normalizedName($name)));
    }

    protected static function booted(): void
    {
        static::saving(function (self $center) {
            if (! $center->exists || $center->isDirty(['name', 'place_id'])) {
                $center->name = static::normalizedName($center->name);
                $center->canonical_key = static::identityKey($center->name, $center->place_id);
            }
        });
    }

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function place()
    {
        return $this->belongsTo(EvacuationPlace::class);
    }

    public function registrations()
    {
        return $this->hasMany(EvacuationRegistration::class, 'center_id');
    }

    public function assignments()
    {
        return $this->hasMany(CampAssignment::class, 'center_id');
    }
}
