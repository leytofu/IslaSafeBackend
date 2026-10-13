<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PasswordResetChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'challenge_id',
        'otp_hash',
        'otp_expires_at',
        'attempts',
        'resend_available_at',
        'status',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'resend_available_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorization(): HasOne
    {
        return $this->hasOne(PasswordResetAuthorization::class, 'challenge_id');
    }
}
