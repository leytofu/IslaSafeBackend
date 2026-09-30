<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SosRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SosRequestController extends Controller
{
    /**
     * GET /api/sos-requests — newest first (polling source for the admin panel).
     */
    public function index(): JsonResponse
    {
        $requests = SosRequest::query()->latestFirst()->get();

        return response()->json([
            'data' => $requests->map(fn (SosRequest $request) => $this->payload($request))->values(),
        ]);
    }

    /**
     * POST /api/sos-requests — emergency submission from a resident device.
     * Deliberately unauthenticated: reporting an emergency must never be
     * blocked by a login screen.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:32'],
            'location' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'type' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:Medical,Flood assistance,Evacuation'],
            'priority' => ['required', 'in:Critical,High,Medium'],
            'description' => ['required', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:Pending,Coming,Resolved'],
        ]);

        $data['status'] ??= SosRequest::STATUS_PENDING;
        $data['received_at'] = now();

        $sosRequest = SosRequest::create($data);

        return response()->json(['data' => $this->payload($sosRequest)], 201);
    }

    /**
     * PATCH /api/sos-requests/{id} — admin/campmanager status updates.
     */
    public function update(Request $request, SosRequest $sosRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', SosRequest::STATUSES)],
        ]);

        $sosRequest->update($data);

        return response()->json(['data' => $this->payload($sosRequest)]);
    }

    private function payload(SosRequest $request): array
    {
        return [
            'id' => $request->id,
            'name' => $request->name,
            'contact' => $request->contact,
            'location' => $request->location,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'type' => $request->type,
            'category' => $request->category,
            'priority' => $request->priority,
            'description' => $request->description,
            'status' => $request->status,
            'received_at' => $request->received_at->toIso8601String(),
        ];
    }
}
