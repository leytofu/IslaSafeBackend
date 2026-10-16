<?php

namespace App\Support;

class Barangays
{
    // Keep aligned with frontend/src/data/barangays.ts and mobile/src/data/barangays.ts.
    public const ALL = ['Aguining', 'Basiao', 'Baud', 'Bayog', 'Bogo', 'Bonbonon', 'Butan',
        'Campamanog', 'Canmangao', 'Gaus', 'Kabangkalan', 'Lapinig', 'Lipata', 'Poblacion',
        'Popoo', 'Saguise', 'San Jose', 'San Vicente', 'Santo Rosario', 'Tilmobo', 'Tugas',
        'Tugnao', 'Villa Milagrosa'];

    public static function canonical(?string $value): ?string
    {
        $key = mb_strtolower(trim($value ?? ''));
        $key = match ($key) {
            'pitogo', 'poblacion (pitogo)' => 'poblacion',
            'tawid', 'san jose (tawid)' => 'san jose',
            default => $key,
        };
        foreach (self::ALL as $barangay) {
            if (mb_strtolower($barangay) === $key) return $barangay;
        }
        return null;
    }
}
