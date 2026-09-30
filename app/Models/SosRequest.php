<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SosRequest extends Model
{
    public const STATUS_PENDING = 'Pending';

    public const STATUS_COMING = 'Coming';

    public const STATUS_RESOLVED = 'Resolved';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMING,
        self::STATUS_RESOLVED,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'contact',
        'location',
        'latitude',
        'longitude',
        'type',
        'category',
        'priority',
        'description',
        'status',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->id === null) {
                $model->id = static::nextCode();
            }
        });
    }

    /**
     * Build the next human-readable code, e.g. SOS-2026-0526.
     * The sequence rolls forward from the highest existing code.
     */
    public static function nextCode(): string
    {
        $lastId = static::query()->orderByDesc('id')->value('id');
        $sequence = $lastId ? ((int) substr($lastId, -4)) + 1 : 1;

        return sprintf('SOS-%s-%04d', now()->year, $sequence);
    }

    /**
     * Newest requests first.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }
}
