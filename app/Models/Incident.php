<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    public const TYPES = ['Typhoon', 'Storm surge', 'Intense rains', 'Landslide', 'Strong winds', 'Others'];
    public const STATUSES = ['Active', 'Monitoring', 'Responding', 'Resolved', 'Closed'];
    public const ACTIVE_STATUSES = ['Active', 'Monitoring', 'Responding'];
    public const SEVERITIES = ['Low', 'Moderate', 'High', 'Critical'];

    protected $fillable = ['type', 'location', 'severity', 'status', 'affected', 'description', 'reported_at', 'reported_by'];

    protected function casts(): array
    {
        return ['reported_at' => 'immutable_datetime'];
    }
}
