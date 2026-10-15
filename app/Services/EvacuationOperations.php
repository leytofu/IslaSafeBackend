<?php

namespace App\Services;

use App\Events\EvacuationCenterUpdated;
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationMovement;
use App\Models\EvacuationRegistration;
use App\Models\Evacuee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvacuationOperations
{
    public function activeEvent(bool $lock = false): ?EvacuationEvent
    {
        $query = DB::table('evacuation_state')->where('id', 1);
        if ($lock) {
            $query->sharedLock();
        }
        $id = $query->value('active_event_id');

        return $id ? EvacuationEvent::find($id) : null;
    }

    public function assignment(User $user, bool $lock = false): CampAssignment
    {
        $query = CampAssignment::where('user_id', $user->id)->with('center.place');
        if ($lock) {
            $query->sharedLock();
        }

        return $query->first() ?? abort(403, 'An administrator must assign your evacuation center and authorized places first.');
    }

    public function visiblePeople(CampAssignment $assignment): Builder
    {
        return Evacuee::query()->where(function ($query) use ($assignment) {
            $query->whereIn('place_id', $assignment->place_ids)->orWhereHas('registrations', fn ($registrations) => $registrations->where('center_id', $assignment->center_id));
        });
    }

    public function person(Evacuee $person): array
    {
        $history = $person->registrations()->with(['event:id,name,status', 'center:id,name', 'initialCenter:id,name'])->orderByDesc('registered_at')->limit(50)->get();
        $total = $person->registrations()->distinct()->count('event_id');
        $active = $this->activeEvent();

        return [...$person->only(['id', 'user_id', 'full_name', 'date_of_birth', 'reported_age', 'age_recorded_at', 'gender', 'place_id', 'purok']),
            'age' => EvacuationDemographics::age($person->date_of_birth?->toDateString(), $person->reported_age),
            'place' => $person->place,
            'total_evacuations' => $total,
            'previous_evacuations' => $person->registrations()->when($active, fn ($q) => $q->where('event_id', '!=', $active->id))->distinct()->count('event_id'),
            'latest_evacuation' => $history->first(),
            'active_registration' => $active ? $person->registrations()->with('center:id,name')->where('event_id', $active->id)->first() : null,
            'history' => $history,
            'history_total' => $total,
        ];
    }

    public function registration(EvacuationRegistration $registration): array
    {
        return $registration->load(['event:id,name,status', 'center:id,name', 'place', 'evacuee'])->toArray();
    }

    public function registrations(EvacuationCenter $center): Builder
    {
        $event = $this->activeEvent();

        return $center->registrations()->getQuery()->where('event_id', $event?->id ?? 0);
    }

    public function summary(EvacuationCenter $center, bool $demographics = false): array
    {
        $event = $this->activeEvent();
        $query = $this->registrations($center);
        $checked = (clone $query)->where('status', 'checked_in');
        $current = (clone $checked)->count();
        $returning = (clone $query)->whereExists(function ($q) {
            $q->selectRaw('1')->from('evacuation_registrations as previous')->whereColumn('previous.evacuee_id', 'evacuation_registrations.evacuee_id')->whereColumn('previous.event_id', '!=', 'evacuation_registrations.event_id');
        })->count();
        $total = (clone $query)->count();
        $activity = EvacuationMovement::where(fn ($q) => $q->where('to_center_id', $center->id)->orWhere('from_center_id', $center->id))->latest('occurred_at')->first();
        $result = [...$center->toArray(), 'place' => $center->place, 'event' => $event, 'registered' => $total,
            'current' => $current, 'first_time' => $total - $returning, 'returning' => $returning,
            'available' => $center->capacity === null ? null : max(0, $center->capacity - $current),
            'occupancy_percent' => $center->capacity ? round($current / $center->capacity * 100, 1) : null,
            'latest_activity' => $activity?->only(['action', 'occurred_at']),
            'total_recorded_events' => EvacuationRegistration::whereHas('movements', fn ($q) => $q->where(fn ($movement) => $movement->where('to_center_id', $center->id)->orWhere('from_center_id', $center->id)))->distinct()->count('event_id'),
        ];
        if ($demographics) {
            $ages = (clone $checked)->selectRaw('age_group, COUNT(*) as total')->groupBy('age_group')->pluck('total', 'age_group')->all();
            $genders = (clone $checked)->selectRaw('gender, COUNT(*) as total')->groupBy('gender')->pluck('total', 'gender')->all();
            $result['demographics'] = [
                'basis' => 'currently_checked_in',
                'age' => array_replace(array_fill_keys(EvacuationDemographics::AGE_GROUPS, 0), $ages),
                'gender' => array_replace(array_fill_keys(EvacuationDemographics::GENDERS, 0), $genders),
                'places' => (clone $checked)->selectRaw('place_id, COUNT(*) as total')->with('place')->groupBy('place_id')->get()->map(fn ($row) => ['place' => $row->place, 'total' => $row->total]),
                'puroks' => (clone $checked)->selectRaw('place_id, purok, COUNT(*) as total')->with('place')->groupBy(['place_id', 'purok'])->get()->map(fn ($row) => ['place' => $row->place, 'purok' => $row->purok, 'total' => $row->total]),
            ];
        }

        return $result;
    }

    public function changed(array $centerIds, ?int $eventId): void
    {
        DB::afterCommit(function () use ($centerIds, $eventId) {
            foreach (array_unique($centerIds) as $centerId) {
                try {
                    event(new EvacuationCenterUpdated((int) $centerId, $eventId));
                } catch (\Throwable $error) {
                    Log::warning('Evacuation broadcast unavailable; clients will reconcile by polling.', ['center_id' => $centerId, 'exception' => $error::class]);
                }
            }
        });
    }

    private function capacity(EvacuationCenter $center, EvacuationEvent $event): void
    {
        abort_if($center->status !== 'Operational', 409, 'This evacuation center is not operational.');
        if ($center->capacity !== null) {
            abort_if($center->registrations()->where('event_id', $event->id)->where('status', 'checked_in')->lockForUpdate()->get(['id'])->count() >= $center->capacity, 409, 'This center has reached its configured capacity.');
        }
    }

    public function register(User $manager, array $data): array
    {
        $result = DB::transaction(function () use ($manager, $data) {
            $event = $this->activeEvent(true) ?? abort(409, 'There is no active evacuation event. Contact your administrator.');
            $assignment = $this->assignment($manager, true);
            // Existing identities lock person then center, matching transfer
            // lock ordering. New drafts serialize on their assigned center.
            $person = isset($data['evacuee_id'])
                ? ($this->visiblePeople($assignment)->whereKey($data['evacuee_id'])->lockForUpdate()->first() ?? abort(404, 'Evacuee record not found in your authorized scope.'))
                : null;
            $center = EvacuationCenter::whereKey($assignment->center_id)->lockForUpdate()->firstOrFail();
            $retry = EvacuationRegistration::where('client_request_id', $data['client_request_id'])->lockForUpdate()->first();
            if ($retry) {
                abort_if($retry->camp_manager_id !== $manager->id || $retry->center_id !== $assignment->center_id, 409, 'This request identifier has already been used.');

                return ['id' => $retry->id, 'already_registered' => true];
            }
            if (isset($data['evacuee_id'])) {
                $existing = $person->registrations()->where('event_id', $event->id)->lockForUpdate()->first();
                if ($existing) {
                    return ['id' => $existing->id, 'already_registered' => true];
                }
            } else {
                abort_unless(in_array((int) $data['place_id'], array_map('intval', $assignment->place_ids), true), 403, 'This place is outside your authorized registration area.');
                $person = Evacuee::create($this->personalData($data));
            }
            $this->capacity($center, $event);
            // Existing profiles may be corrected only during an authorized registration.
            if (isset($data['evacuee_id']) && isset($data['full_name'])) {
                abort_unless(in_array((int) $data['place_id'], array_map('intval', $assignment->place_ids), true), 403, 'This place is outside your authorized registration area.');
                $person->update($this->personalData($data));
            }
            $age = EvacuationDemographics::age($person->date_of_birth?->toDateString(), $person->reported_age);
            $registration = EvacuationRegistration::create([
                'client_request_id' => $data['client_request_id'], 'evacuee_id' => $person->id, 'event_id' => $event->id,
                'center_id' => $center->id, 'initial_center_id' => $center->id, 'camp_manager_id' => $manager->id,
                'registered_at' => now(), 'status' => 'checked_in', 'full_name' => $person->full_name, 'age' => $age,
                'age_group' => EvacuationDemographics::group($age), 'gender' => $person->gender, 'place_id' => $person->place_id, 'purok' => $person->purok,
            ]);
            EvacuationMovement::create(['registration_id' => $registration->id, 'to_center_id' => $center->id, 'actor_id' => $manager->id, 'action' => 'registered', 'occurred_at' => now()]);
            $this->changed([$center->id], $event->id);

            return ['id' => $registration->id, 'already_registered' => false];
        }, 5);
        $registration = EvacuationRegistration::findOrFail($result['id']);

        return ['registration' => $this->registration($registration), 'person' => $this->person($registration->evacuee), 'already_registered' => $result['already_registered']];
    }

    public function personalData(array $data): array
    {
        $clean = fn (?string $value) => $value === null ? null : preg_replace('/\s+/u', ' ', trim($value));

        return ['full_name' => $clean($data['full_name']), 'date_of_birth' => $data['date_of_birth'] ?? null, 'reported_age' => ($data['date_of_birth'] ?? null) ? null : ($data['reported_age'] ?? null),
            'age_recorded_at' => isset($data['reported_age']) ? now()->toDateString() : null, 'gender' => $data['gender'] ?? 'unspecified', 'place_id' => $data['place_id'], 'purok' => $clean($data['purok'] ?? null)];
    }

    public function move(User $actor, int $id, string $action, ?int $targetId): EvacuationRegistration
    {
        return DB::transaction(function () use ($actor, $id, $action, $targetId) {
            $event = $this->activeEvent(true) ?? abort(409, 'There is no active evacuation event.');
            $initial = EvacuationRegistration::findOrFail($id);
            Evacuee::whereKey($initial->evacuee_id)->lockForUpdate()->firstOrFail();
            $registration = EvacuationRegistration::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($registration->event_id !== $event->id, 409, 'Historical registrations cannot be changed.');
            if ($actor->role !== User::ROLE_ADMIN) {
                $assignment = $this->assignment($actor, true);
                abort_if($registration->center_id !== $assignment->center_id || $action === 'transfer', 403, 'This action is outside your center permissions.');
            }
            $from = $registration->center_id;
            $targetId = $action === 'transfer' ? $targetId : $from;
            $centers = EvacuationCenter::whereIn('id', array_unique([$from, $targetId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $target = $centers->get($targetId) ?? abort(422, 'Select a valid destination center.');
            if ($action === 'transfer') {
                abort_if($registration->status !== 'checked_in' || $from === $targetId, 409, 'Only a checked-in person can transfer to another center.');
                $this->capacity($target, $event);
                $registration->center_id = $targetId;
            } elseif ($action === 'check_in') {
                abort_if($registration->status === 'checked_in', 409, 'This person is already checked in.');
                $this->capacity($target, $event);
                $registration->status = 'checked_in';
                $registration->checked_out_at = null;
            } else {
                abort_if($registration->status !== 'checked_in', 409, 'This person is already checked out.');
                $registration->status = 'checked_out';
                $registration->checked_out_at = now();
            }
            $registration->save();
            EvacuationMovement::create(['registration_id' => $id, 'from_center_id' => $from, 'to_center_id' => $action === 'check_out' ? null : $targetId, 'actor_id' => $actor->id, 'action' => $action, 'occurred_at' => now()]);
            $this->changed([$from, $targetId], $event->id);

            return $registration;
        }, 5);
    }
}
