<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\Incident;
use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminHeaderController extends Controller
{
    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);
        $term = trim($data['q']);
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        // Literal, case-insensitive substring matching, including % and _.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        $match = function ($query, array $columns) use ($like) {
            $query->where(function ($where) use ($columns, $like) {
                foreach ($columns as $column) {
                    $where->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$like]);
                }
            });
        };
        $sos = SosRequest::query();
        $match($sos, ['id', 'name', 'location', 'type']);
        $incidents = Incident::query();
        $match($incidents, ['type', 'location', 'description']);
        if (preg_match('/^(?:INC-)?0*(\d+)$/i', $term, $id)) {
            $incidents->orWhere('id', (int) $id[1]);
        }
        $centers = EvacuationCenter::with('place')->where(function ($query) use ($match, $like) {
            $match($query, ['name']);
            $query->orWhereHas('place', fn ($place) => $place->whereRaw("LOWER(barangay) LIKE ? ESCAPE '!'", [$like]));
        });
        $residents = User::where('role', User::ROLE_RESIDENT);
        $match($residents, ['name', 'email', 'barangay', 'address']);

        $results = collect()->concat($sos->latest('received_at')->limit(5)->get()->map(fn ($row) => [
            'kind' => 'sos', 'id' => $row->id, 'title' => $row->name,
            'subtitle' => $row->id.' · '.$row->location, 'status' => $row->status,
        ]))->concat($incidents->latest('reported_at')->limit(5)->get()->map(fn ($row) => [
            'kind' => 'incident', 'id' => (string) $row->id, 'title' => $row->type,
            'subtitle' => sprintf('INC-%04d', $row->id).' · '.$row->location, 'status' => $row->status,
        ]))->concat($centers->orderBy('name')->limit(5)->get()->map(fn ($row) => [
            'kind' => 'center', 'id' => (string) $row->id, 'title' => $row->name,
            'subtitle' => $row->place?->barangay ?? 'Evacuation center', 'status' => $row->status,
        ]))->concat($residents->orderBy('name')->limit(5)->get()->map(fn ($row) => [
            'kind' => 'resident', 'id' => (string) $row->id, 'title' => $row->name,
            'subtitle' => $row->barangay ?: $row->email, 'status' => 'Resident account',
            'details' => [
                ['label' => 'Email', 'value' => $row->email],
                ['label' => 'Contact number', 'value' => $row->contact_number ?: 'Not recorded'],
                ['label' => 'Barangay', 'value' => $row->barangay ?: 'Not recorded'],
                ['label' => 'Address', 'value' => $row->address ?: 'Not recorded'],
            ],
        ]));

        return response()->json(['data' => $results->values()]);
    }

    private function activity()
    {
        $sos = SosRequest::latest('updated_at')->limit(20)->get()->map(fn ($row) => [
            'kind' => 'sos', 'id' => $row->id, 'title' => $row->type.' · '.$row->status,
            'subtitle' => $row->id.' · '.$row->name.' · '.$row->location,
            'occurred_at' => ($row->updated_at ?? $row->received_at)->toIso8601String(),
        ]);
        $incidents = Incident::latest('updated_at')->limit(20)->get()->map(fn ($row) => [
            'kind' => 'incident', 'id' => (string) $row->id, 'title' => $row->type.' · '.$row->status,
            'subtitle' => sprintf('INC-%04d', $row->id).' · '.$row->location,
            'occurred_at' => $row->updated_at->toIso8601String(),
        ]);

        return $sos->concat($incidents)->map(function ($item) {
            $item['key'] = hash('sha256', json_encode($item));
            return $item;
        })->sortByDesc('occurred_at')->values();
    }

    public function notifications(Request $request)
    {
        $items = $this->activity();
        $read = DB::table('admin_activity_reads')->where('user_id', $request->user()->id)
            ->whereIn('activity_key', $items->pluck('key'))->pluck('activity_key')->flip();
        $items = $items->map(function ($item) use ($read) {
            $item['read'] = $read->has($item['key']);
            return $item;
        });

        return response()->json(['data' => $items, 'unread_count' => $items->where('read', false)->count()]);
    }

    public function markRead(Request $request)
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:40'],
            'keys.*' => ['required', 'string', 'size:64', 'distinct'],
        ]);
        // Mark only the supplied snapshot, never new events arriving afterwards.
        $keys = $this->activity()->pluck('key')->intersect($data['keys']);
        $rows = $keys->map(fn ($key) => [
            'user_id' => $request->user()->id, 'activity_key' => $key, 'read_at' => now(),
        ])->all();
        if ($rows) {
            DB::table('admin_activity_reads')->insertOrIgnore($rows);
        }

        return $this->notifications($request);
    }
}
