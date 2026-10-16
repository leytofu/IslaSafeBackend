<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Support\Barangays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class AdvisoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Advisory::orderByDesc('published_at')->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['status'] = 'Active';
        $data['published_at'] = now();
        $data['published_by'] = $request->user()->id;
        return response()->json(['data' => Advisory::create($data)], 201);
    }

    public function update(Request $request, Advisory $advisory): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $advisory = DB::transaction(function () use ($advisory, $data) {
            $record = Advisory::lockForUpdate()->findOrFail($advisory->id);
            if ($data['status'] === 'Active' && $record->status === 'Archived') {
                $data['published_at'] = now();
            }
            $record->fill($data);
            if ($record->isDirty()) {
                $record->notification_version++;
                $record->save();
            }
            return $record;
        });
        return response()->json(['data' => $advisory->fresh()]);
    }

    private function rules(bool $updating = false): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['Critical', 'Warning', 'Info'])],
            'target' => ['required', Rule::in(['All barangays', ...Barangays::ALL])],
            'status' => [$updating ? 'required' : 'sometimes', Rule::in(['Active', 'Archived'])],
        ];
    }
}
