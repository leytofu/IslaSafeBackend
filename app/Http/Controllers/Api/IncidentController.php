<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Incident::query()->orderByDesc('reported_at')->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['reported_at'] = CarbonImmutable::parse($data['reported_at'])->utc();
        $data['reported_by'] = $request->user()->id;

        return response()->json(['data' => Incident::create($data)], 201);
    }

    public function update(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['reported_at'] = CarbonImmutable::parse($data['reported_at'])->utc();
        $incident->update($data);

        return response()->json(['data' => $incident->fresh()]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['sometimes', Rule::in(['week', 'month', 'year', 'all'])]]);
        $period = $data['period'] ?? 'week';
        $now = CarbonImmutable::now('Asia/Manila');
        $start = match ($period) {
            'week' => $now->startOfWeek(1),
            'month' => $now->startOfMonth(),
            'year' => $now->startOfYear(),
            default => null,
        };
        $query = Incident::query()->where('reported_at', '<=', $now->utc());
        if ($start) {
            $query->where('reported_at', '>=', $start->utc());
        }
        // One grouped query keeps the chart total and status counts consistent.
        $groups = $query->selectRaw('type, status, COUNT(*) as total')->groupBy('type', 'status')->get();
        $total = (int) $groups->sum('total');
        $active = (int) $groups->whereIn('status', Incident::ACTIVE_STATUSES)->sum('total');

        return response()->json(['data' => [
            'period' => $period,
            'timezone' => 'Asia/Manila',
            'from' => $start?->toIso8601String(),
            'to' => $now->toIso8601String(),
            'total' => $total,
            'active' => $active,
            'resolved' => $total - $active,
            'active_all' => Incident::query()->whereIn('status', Incident::ACTIVE_STATUSES)->count(),
            'breakdown' => collect(Incident::TYPES)->map(fn ($type) => [
                'type' => $type, 'count' => (int) $groups->where('type', $type)->sum('total'),
            ])->values(),
        ]]);
    }

    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Incident::TYPES)],
            'location' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'status' => ['required', Rule::in(Incident::STATUSES)],
            'reported_at' => ['required', 'date', 'before_or_equal:now'],
            'affected' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
