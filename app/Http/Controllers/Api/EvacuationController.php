<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationMovement;
use App\Models\EvacuationPlace;
use App\Models\EvacuationRegistration;
use App\Models\Evacuee;
use App\Models\User;
use App\Services\EvacuationDemographics;
use App\Services\EvacuationOperations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvacuationController extends Controller
{
    public function __construct(private readonly EvacuationOperations $operations) {}

    private function personalRules(bool $optional = false): array
    {
        return ['full_name' => [$optional ? 'required_without:evacuee_id' : 'required', 'string', 'max:180'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYears(125)->toDateString()],
            'reported_age' => ['nullable', 'integer', 'between:0,125'], 'gender' => ['nullable', Rule::in(EvacuationDemographics::GENDERS)],
            'place_id' => [$optional ? 'required_without:evacuee_id' : 'required', 'required_with:full_name', 'integer', 'exists:evacuation_places,id'], 'purok' => ['nullable', 'string', 'max:120']];
    }

    public function context(Request $request)
    {
        $assignment = $this->operations->assignment($request->user());

        return response()->json(['data' => [
            'center' => $this->operations->summary($assignment->center, true),
            'event' => $this->operations->activeEvent(),
            'places' => EvacuationPlace::whereIn('id', $assignment->place_ids)->orderBy('barangay')->get(),
            'genders' => EvacuationDemographics::GENDERS,
            'recent' => $this->operations->registrations($assignment->center)->with('place')->latest('registered_at')->limit(5)->get(),
        ]]);
    }

    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);
        $assignment = $this->operations->assignment($request->user());
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['q']));
        $people = $this->operations->visiblePeople($assignment)->where('full_name', 'like', $term.'%')->with('place')->orderBy('full_name')->limit(15)->get();

        // Search exposes only identity hints. History requires an explicit selection.
        return response()->json(['data' => $people->map(fn ($person) => ['id' => $person->id, 'user_id' => $person->user_id, 'full_name' => $person->full_name, 'age' => EvacuationDemographics::age($person->date_of_birth?->toDateString(), $person->reported_age), 'place' => $person->place])]);
    }

    public function person(Request $request, int $person)
    {
        $assignment = $this->operations->assignment($request->user());
        $record = $this->operations->visiblePeople($assignment)->whereKey($person)->firstOrFail();

        return response()->json(['data' => $this->operations->person($record)]);
    }

    public function roster(Request $request, ?EvacuationCenter $center = null)
    {
        if ($request->user()->role !== User::ROLE_ADMIN) {
            $center = $this->operations->assignment($request->user())->center;
        }
        abort_unless($center, 404);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['checked_in', 'checked_out'])], 'place_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->operations->registrations($center)->with(['place', 'evacuee']);
        if (! empty($data['q'])) {
            $query->where('full_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%');
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['place_id'])) {
            $query->where('place_id', $data['place_id']);
        }

        return response()->json($query->latest('registered_at')->paginate(25));
    }

    public function groupedRoster(Request $request, EvacuationCenter $center)
    {
        $ages = ['children' => ['Children', '0-12 years', 0, 12], 'teens' => ['Teens', '13-17 years', 13, 17],
            'adults' => ['Adults', '18 years and above', 18, 125], 'unknown' => ['Unknown age', 'Age not recorded', null, null]];
        $genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other / unspecified'];
        $rules = ['q' => ['nullable', 'string', 'max:120'], 'place_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['checked_in', 'checked_out'])]];
        foreach ($ages as $ageKey => $age) {
            foreach ($genders as $genderKey => $gender) {
                $rules[$ageKey.'_'.$genderKey.'_page'] = ['nullable', 'integer', 'min:1'];
            }
        }
        $data = $request->validate($rules);
        // This route is admin-only. Keep every query scoped to this center and
        // the active event, including totals and independently paged groups.
        $query = $this->operations->registrations($center)->with('place');
        if (! empty($data['q'])) {
            $query->where('full_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%');
        }
        foreach (['place_id', 'status'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        $groups = [];
        foreach ($ages as $ageKey => [$title, $range, $minimum, $maximum]) {
            $ageQuery = clone $query;
            $minimum === null ? $ageQuery->whereNull('age') : $ageQuery->whereBetween('age', [$minimum, $maximum]);
            $sections = [];
            foreach ($genders as $genderKey => $genderTitle) {
                $genderQuery = clone $ageQuery;
                $genderKey === 'other' ? $genderQuery->whereNotIn('gender', ['male', 'female']) : $genderQuery->where('gender', $genderKey);
                $pageName = $ageKey.'_'.$genderKey.'_page';
                $page = $genderQuery->orderBy('full_name')->orderBy('id')->paginate(25, ['*'], $pageName, $data[$pageName] ?? 1);
                if ($genderKey !== 'other' || $page->total() > 0) {
                    $sections[] = ['key' => $genderKey, 'title' => $genderTitle, 'page_key' => $pageName,
                        'data' => $page->items(), 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()];
                }
            }
            $total = array_sum(array_column($sections, 'total'));
            if ($ageKey !== 'unknown' || $total > 0) {
                $groups[] = ['key' => $ageKey, 'title' => $title, 'range' => $range, 'total' => $total, 'genders' => $sections];
            }
        }

        $places = $this->operations->registrations($center)->select('place_id')->distinct()->with('place')->get()->pluck('place')->values();

        return response()->json(['data' => $groups, 'total' => array_sum(array_column($groups, 'total')), 'event' => $this->operations->activeEvent(), 'places' => $places]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([...$this->personalRules(true), 'evacuee_id' => ['nullable', 'integer'], 'client_request_id' => ['required', 'uuid'],
            'center_id' => ['prohibited'], 'event_id' => ['prohibited'], 'camp_manager_id' => ['prohibited'], 'user_id' => ['prohibited'], 'registered_at' => ['prohibited'], 'status' => ['prohibited']]);
        $result = $this->operations->register($request->user(), $data);
        $result['center'] = $this->operations->summary($this->operations->assignment($request->user())->center, true);

        return response()->json(['data' => $result, 'message' => $result['already_registered'] ? 'Already registered for the current evacuation event.' : 'Registration successful.'], $result['already_registered'] ? 200 : 201);
    }

    public function move(Request $request, int $registration)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['transfer', 'check_in', 'check_out'])], 'center_id' => ['required_if:action,transfer', 'nullable', 'integer', 'exists:evacuation_centers,id']]);
        $result = $this->operations->move($request->user(), $registration, $data['action'], $data['center_id'] ?? null);

        return response()->json(['data' => $this->operations->registration($result)]);
    }

    public function centers(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = EvacuationCenter::with('place')->orderBy('name');
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        $page = $query->paginate(24);
        $page->setCollection($page->getCollection()->map(fn ($center) => $this->operations->summary($center)));

        return response()->json($page);
    }

    public function center(EvacuationCenter $center)
    {
        return response()->json(['data' => $this->operations->summary($center, true)]);
    }

    public function mapCenters(Request $request)
    {
        $admin = $request->is('api/admin/*');
        $event = $this->operations->activeEvent();
        $centers = EvacuationCenter::with('place:id,barangay,municipality')
            ->when(! $admin, fn ($query) => $query->where('status', 'Operational'))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->withCount(['registrations as current' => fn ($query) => $query->where('event_id', $event?->id ?? 0)->where('status', 'checked_in')])
            ->orderBy('name')->get();

        return response()->json(['data' => $centers->map(fn ($center) => [
            ...$center->only(['id', 'name', 'capacity', 'status', 'latitude', 'longitude']),
            'place' => $center->place, 'current' => (int) $center->current,
            'available' => $center->capacity === null ? null : max(0, $center->capacity - $center->current),
        ])])->header('Cache-Control', 'no-store');
    }

    private function centerData(Request $request, ?EvacuationCenter $center = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => EvacuationCenter::normalizedName($request->input('name'))]);
        }
        $required = $center ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'required', 'string', 'max:180'],
            'place_id' => [$required, 'required', 'integer', 'exists:evacuation_places,id'],
            'capacity' => [$required, 'required', 'integer', 'between:1,100000'],
            'latitude' => [$required, 'required', 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'required', 'numeric', 'between:-180,180'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'required', Rule::in(['Operational', 'Closed', 'Unavailable'])],
            'camp_manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', User::ROLE_CAMP_MANAGER)],
        ]);
        $name = $data['name'] ?? $center?->name;
        $placeId = $data['place_id'] ?? $center?->place_id;
        $key = EvacuationCenter::identityKey($name, $placeId);
        $duplicate = EvacuationCenter::where('id', '!=', $center?->id ?? 0)
            ->where(function ($query) use ($key, $placeId) {
                $query->where('canonical_key', $key)->orWhere(fn ($legacy) => $legacy->whereNull('canonical_key')->where('place_id', $placeId));
            })->get()->contains(fn ($row) => EvacuationCenter::identityKey($row->name, $row->place_id) === $key);
        if ($duplicate && (! $center || isset($data['name']) || isset($data['place_id']))) {
            throw ValidationException::withMessages(['name' => 'An evacuation center with this name already exists in this barangay. Edit or reactivate the existing center.']);
        }

        return $data;
    }

    private function assignCenterManager(EvacuationCenter $center, ?int $managerId): void
    {
        if ($managerId === null) {
            return;
        }
        // Serialize against other assignments of this manager, without silently
        // moving a manager away from a different center.
        User::whereKey($managerId)->lockForUpdate()->firstOrFail();
        $existing = CampAssignment::where('user_id', $managerId)->first();
        if ($existing && $existing->center_id !== $center->id) {
            throw ValidationException::withMessages(['camp_manager_id' => 'This camp manager is already assigned to another center. Choose an available manager.']);
        }
        if (! $existing) {
            CampAssignment::create(['user_id' => $managerId, 'center_id' => $center->id, 'place_ids' => [$center->place_id]]);
        }
    }

    private function saveCenter(Request $request, ?EvacuationCenter $center = null): EvacuationCenter
    {
        $data = $this->centerData($request, $center);
        $managerId = $data['camp_manager_id'] ?? null;
        unset($data['camp_manager_id']);
        try {
            return DB::transaction(function () use ($center, $data, $managerId) {
                if ($center) {
                    $locked = EvacuationCenter::whereKey($center->id)->lockForUpdate()->firstOrFail();
                    $occupancy = $this->operations->registrations($locked)->where('status', 'checked_in')->count();
                    abort_if(isset($data['capacity']) && $data['capacity'] < $occupancy, 409, 'Capacity cannot be below current occupancy.');
                    abort_if(isset($data['status']) && $data['status'] !== 'Operational' && $occupancy > 0, 409, 'Check out or transfer occupants before deactivating a center.');
                    $locked->update($data);
                    if ($locked->wasChanged('place_id')) {
                        // Relocating a facility must not strand its managers with
                        // the previous barangay only. Retain wider existing coverage.
                        foreach (CampAssignment::where('center_id', $locked->id)->lockForUpdate()->get() as $assignment) {
                            $places = array_map('intval', $assignment->place_ids);
                            $assignment->update(['place_ids' => array_values(array_unique([...$places, (int) $locked->place_id]))]);
                        }
                    }
                } else {
                    $locked = EvacuationCenter::create($data);
                }
                $this->assignCenterManager($locked, $managerId);
                $this->operations->changed([$locked->id], $this->operations->activeEvent()?->id);

                return $locked;
            }, 5);
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['name' => 'This center or manager assignment already exists. Refresh and check the existing records.']);
        }
    }

    public function createCenter(Request $request)
    {
        $center = $this->saveCenter($request);

        return response()->json(['data' => $this->operations->summary($center)], 201);
    }

    public function updateCenter(Request $request, EvacuationCenter $center)
    {
        return $this->center($this->saveCenter($request, $center)->fresh());
    }

    public function deleteCenter(EvacuationCenter $center)
    {
        DB::transaction(function () use ($center) {
            $locked = EvacuationCenter::whereKey($center->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->registrations()->where('status', 'checked_in')->exists(), 409, 'Check out or transfer occupants before deleting this center.');
            $hasRegistrations = EvacuationRegistration::where('center_id', $locked->id)->orWhere('initial_center_id', $locked->id)->exists();
            $hasMovements = EvacuationMovement::where('from_center_id', $locked->id)->orWhere('to_center_id', $locked->id)->exists();
            abort_if($hasRegistrations || $hasMovements, 409, 'This center has evacuation history and cannot be deleted. Deactivate it to preserve evacuee records.');
            // Release assignments, retaining the Camp Manager accounts.
            $locked->assignments()->delete();
            $locked->delete();
            $this->operations->changed([$center->id], $this->operations->activeEvent()?->id);
        }, 5);

        return response()->json(['message' => 'Evacuation center deleted.']);
    }

    public function setup()
    {
        return response()->json(['data' => ['places' => EvacuationPlace::orderBy('barangay')->get(), 'event' => $this->operations->activeEvent(),
            'events' => EvacuationEvent::latest('started_at')->limit(30)->get(), 'managers' => User::where('role', User::ROLE_CAMP_MANAGER)->select('id', 'name')->orderBy('name')->get(),
            'assignments' => CampAssignment::with('center:id,name', 'user:id,name')->get(), 'genders' => EvacuationDemographics::GENDERS]]);
    }

    public function place(Request $request)
    {
        $data = $request->validate(['barangay' => ['required', 'string', 'max:120'], 'municipality' => ['required', 'string', 'max:120']]);
        $data = array_map(fn ($value) => preg_replace('/\s+/u', ' ', trim($value)), $data);
        $key = mb_strtolower($data['municipality'].'|'.$data['barangay']);

        return response()->json(['data' => EvacuationPlace::firstOrCreate(['canonical_key' => $key], $data)], 201);
    }

    public function assign(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_CAMP_MANAGER)], 'center_id' => ['required', 'exists:evacuation_centers,id'], 'place_ids' => ['required', 'array', 'min:1'], 'place_ids.*' => ['integer', 'distinct', 'exists:evacuation_places,id']]);
        $assignment = DB::transaction(function () use ($data) {
            User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();

            return CampAssignment::updateOrCreate(['user_id' => $data['user_id']], $data);
        });

        return response()->json(['data' => $assignment]);
    }

    public function createEvent(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180']]);
        $event = DB::transaction(function () use ($data) {
            $state = DB::table('evacuation_state')->where('id', 1)->lockForUpdate()->first();
            abort_if($state->active_event_id !== null, 409, 'Close the active event before opening another.');
            $event = EvacuationEvent::create([...$data, 'status' => 'active', 'started_at' => now()]);
            DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => $event->id]);
            $this->operations->changed(EvacuationCenter::pluck('id')->all(), $event->id);

            return $event;
        });

        return response()->json(['data' => $event], 201);
    }

    public function closeEvent(EvacuationEvent $event, Request $request)
    {
        DB::transaction(function () use ($event, $request) {
            $state = DB::table('evacuation_state')->where('id', 1)->lockForUpdate()->first();
            abort_unless((int) $state->active_event_id === $event->id, 409, 'This event is not active.');
            EvacuationRegistration::where('event_id', $event->id)->where('status', 'checked_in')->chunkById(250, function ($rows) use ($request) {
                foreach ($rows as $row) {
                    $row->update(['status' => 'checked_out', 'checked_out_at' => now()]);
                    EvacuationMovement::create(['registration_id' => $row->id, 'from_center_id' => $row->center_id, 'actor_id' => $request->user()->id, 'action' => 'event_closed', 'occurred_at' => now()]);
                }
            });
            $event->update(['status' => 'closed', 'ended_at' => now()]);
            DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => null]);
            $this->operations->changed(EvacuationCenter::pluck('id')->all(), null);
        });

        return response()->json(['data' => $event->fresh()]);
    }

    public function residents(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return response()->json(['data' => User::where('role', User::ROLE_RESIDENT)->where('name', 'like', $data['q'].'%')->select('id', 'name')->limit(20)->get()]);
    }

    public function linkResident(Request $request)
    {
        $data = $request->validate([...$this->personalRules(), 'user_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_RESIDENT)]]);
        $person = DB::transaction(function () use ($data) {
            User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();

            return Evacuee::updateOrCreate(['user_id' => $data['user_id']], $this->operations->personalData($data));
        });

        return response()->json(['data' => $this->operations->person($person)]);
    }
}
