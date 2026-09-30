<?php

namespace Database\Seeders;

use App\Models\SosRequest;
use Illuminate\Database\Seeder;

class SosRequestSeeder extends Seeder
{
    /**
     * Seed the records the admin panel ships with, using stable codes so
     * re-running the seeder never duplicates them.
     */
    public function run(): void
    {
        $records = [
            [
                'id' => 'SOS-2024-0525',
                'name' => 'Maria Dela Cruz',
                'contact' => '0917-XXX-1208',
                'location' => 'Purok 2, Pitogo',
                'latitude' => 10.118,
                'longitude' => 124.561,
                'type' => 'Medical emergency',
                'category' => 'Medical',
                'priority' => 'Critical',
                'description' => 'Elderly resident experiencing chest pain and shortness of breath. Medical transport is requested immediately.',
                'status' => 'Pending',
                'received_at' => now()->subHours(2),
            ],
            [
                'id' => 'SOS-2024-0524',
                'name' => 'Rogelio Ramos',
                'contact' => '0917-XXX-1830',
                'location' => 'Purok 4, Lapinig',
                'latitude' => 10.126,
                'longitude' => 124.545,
                'type' => 'Flood assistance',
                'category' => 'Flood assistance',
                'priority' => 'High',
                'description' => 'Floodwater is entering the family home. Four residents need evacuation assistance and supplies.',
                'status' => 'Pending',
                'received_at' => now()->subHours(6),
            ],
            [
                'id' => 'SOS-2024-0523',
                'name' => 'Jennifer Reyes',
                'contact' => '0917-XXX-1142',
                'location' => 'Purok 1, San Vicente',
                'latitude' => 10.116,
                'longitude' => 124.572,
                'type' => 'Evacuation request',
                'category' => 'Evacuation',
                'priority' => 'High',
                'description' => 'Requester is with two children and needs transport to the nearest active evacuation center.',
                'status' => 'Resolved',
                'received_at' => now()->subDays(4),
            ],
            [
                'id' => 'SOS-2024-0522',
                'name' => 'Alberto Villanueva',
                'contact' => '0917-XXX-1712',
                'location' => 'Purok 3, Baud',
                'latitude' => 10.103,
                'longitude' => 124.580,
                'type' => 'Medical transport',
                'category' => 'Medical',
                'priority' => 'Medium',
                'description' => 'Resident requires non-critical medical transport after first aid was administered by local responders.',
                'status' => 'Resolved',
                'received_at' => now()->subDays(6),
            ],
            [
                'id' => 'SOS-2024-0521',
                'name' => 'Lorna May Flores',
                'contact' => '0917-XXX-1684',
                'location' => 'Purok 5, Tugas',
                'latitude' => 10.091,
                'longitude' => 124.545,
                'type' => 'Flood assistance',
                'category' => 'Flood assistance',
                'priority' => 'Medium',
                'description' => 'Flooding affected the household access route. Requester needs assistance moving to a safer area.',
                'status' => 'Resolved',
                'received_at' => now()->subDays(18),
            ],
            [
                'id' => 'SOS-2024-0520',
                'name' => 'Jericho M. Santos',
                'contact' => '0917-XXX-1326',
                'location' => 'Purok 1, Aguining',
                'latitude' => 10.104,
                'longitude' => 124.532,
                'type' => 'Evacuation request',
                'category' => 'Evacuation',
                'priority' => 'High',
                'description' => 'Resident requested evacuation after severe winds damaged the home roof. One adult and two dependents were assisted.',
                'status' => 'Resolved',
                'received_at' => now()->subDays(27),
            ],
        ];

        foreach ($records as $record) {
            SosRequest::firstOrCreate(['id' => $record['id']], $record);
        }
    }
}
