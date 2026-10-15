<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class EvacuationDemographics
{
    public const GENDERS = ['male', 'female', 'non_binary', 'other', 'unspecified'];

    public const AGE_GROUPS = ['infants', 'children', 'teenagers', 'adults', 'seniors', 'unknown'];

    public static function age(?string $birthDate, ?int $reported, ?Carbon $at = null): ?int
    {
        return $birthDate ? (int) Carbon::parse($birthDate)->diffInYears($at ?? now()) : $reported;
    }

    public static function group(?int $age): string
    {
        return match (true) {
            $age === null => 'unknown', $age <= 2 => 'infants', $age <= 12 => 'children', $age <= 17 => 'teenagers', $age <= 59 => 'adults', default => 'seniors'
        };
    }
}
