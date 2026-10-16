<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Models\User;
use App\Support\Barangays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvisoryInboxController extends Controller
{
    private function visible(User $user): Builder
    {
        $targets = ['All barangays', Barangays::canonical($user->barangay)];
        if ($user->role === User::ROLE_CAMP_MANAGER) {
            $targets[] = Barangays::canonical($user->campAssignment?->center?->place?->barangay);
        }
        return Advisory::where('status', 'Active')->whereIn('target', array_filter(array_unique($targets)));
    }

    private function version(Advisory $advisory): string
    {
        return hash('sha256', $advisory->id.'|'.$advisory->notification_version);
    }

    public function index(Request $request): JsonResponse
    {
        $reads = DB::table('advisory_reads')->where('user_id', $request->user()->id)->get()->keyBy('advisory_id');
        $items = $this->visible($request->user())->orderByDesc('published_at')->orderByDesc('id')->get()
            ->map(function (Advisory $advisory) use ($reads) {
                $version = $this->version($advisory);
                $read = $reads->get($advisory->id);
                return array_merge($advisory->toArray(), ['version' => $version, 'is_read' => $read?->version === $version]);
            });
        return response()->json(['data' => $items, 'unread_count' => $items->where('is_read', false)->count()]);
    }

    public function markRead(Request $request, int $advisory): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:64']]);
        $record = $this->visible($request->user())->findOrFail($advisory);
        abort_unless(hash_equals($this->version($record), $data['version']), 409, 'This advisory has changed. Refresh before marking it as read.');
        DB::table('advisory_reads')->upsert([[
            'user_id' => $request->user()->id, 'advisory_id' => $record->id,
            'version' => $data['version'], 'read_at' => now(),
        ]], ['user_id', 'advisory_id'], ['version', 'read_at']);
        return response()->json(['message' => 'Advisory marked as read.']);
    }
}
