<?php

namespace Tests\Feature;

use App\Events\EvacuationCenterUpdated;
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationPlace;
use App\Models\EvacuationRegistration;
use App\Models\Evacuee;
use App\Models\User;
use App\Services\EvacuationDemographics;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvacuationTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private User $manager;

    private EvacuationPlace $place;

    private EvacuationCenter $center;

    private EvacuationEvent $evacuation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manager = User::factory()->create(['role' => 'campmanager']);
        $this->place = EvacuationPlace::create(['barangay' => 'Test Barangay', 'municipality' => 'Test Municipality', 'canonical_key' => 'test|place']);
        $this->center = EvacuationCenter::create(['name' => 'Test Center', 'place_id' => $this->place->id, 'capacity' => 10]);
        $this->evacuation = EvacuationEvent::create(['name' => 'Test Event', 'started_at' => now()]);
        DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => $this->evacuation->id]);
        CampAssignment::create(['user_id' => $this->manager->id, 'center_id' => $this->center->id, 'place_ids' => [$this->place->id]]);
    }

    private function body(array $extra = []): array
    {
        return [...['client_request_id' => (string) Str::uuid(), 'full_name' => 'Test Evacuee', 'reported_age' => 35, 'gender' => 'non_binary', 'place_id' => $this->place->id, 'purok' => 'Purok 1'], ...$extra];
    }

    private function register(array $extra = [])
    {
        return $this->actingAs($this->manager)->postJson('/api/camp/registrations', $this->body($extra));
    }

    public function test_registers_real_event_history_and_demographics(): void
    {
        $result = $this->register()->assertCreated()->assertJsonPath('data.person.total_evacuations', 1)->assertJsonPath('data.center.current', 1)->assertJsonPath('data.center.demographics.gender.non_binary', 1)->assertJsonPath('data.center.demographics.age.adults', 1);
        $this->assertDatabaseHas('evacuation_registrations', ['id' => $result->json('data.registration.id'), 'center_id' => $this->center->id, 'camp_manager_id' => $this->manager->id, 'event_id' => $this->evacuation->id]);
        $this->assertDatabaseCount('evacuation_movements', 1);
    }

    public function test_identical_retry_is_idempotent_even_for_a_new_person(): void
    {
        $body = $this->body();
        $first = $this->actingAs($this->manager)->postJson('/api/camp/registrations', $body)->assertCreated();
        $this->postJson('/api/camp/registrations', $body)->assertOk()->assertJsonPath('data.already_registered', true)->assertJsonPath('data.registration.id', $first->json('data.registration.id'));
        $this->assertDatabaseCount('evacuees', 1);
        $this->assertDatabaseCount('evacuation_registrations', 1);
    }

    public function test_duplicate_person_in_the_same_event_returns_existing_details(): void
    {
        $first = $this->register()->assertCreated();
        $this->register(['evacuee_id' => $first->json('data.person.id')])->assertOk()->assertJsonPath('data.already_registered', true)->assertJsonPath('data.person.total_evacuations', 1);
        $this->assertDatabaseCount('evacuation_registrations', 1);
    }

    public function test_database_unique_constraint_prevents_duplicate_person_event(): void
    {
        $first = $this->register()->assertCreated();
        $row = EvacuationRegistration::findOrFail($first->json('data.registration.id'));
        $duplicate = $row->replicate();
        $duplicate->client_request_id = (string) Str::uuid();
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    public function test_similar_names_are_not_merged(): void
    {
        $this->register()->assertCreated();
        $this->register()->assertCreated();
        $this->assertDatabaseCount('evacuees', 2);
        $this->assertDatabaseCount('evacuation_registrations', 2);
    }

    public function test_returning_person_is_counted_by_distinct_events_not_occupancy(): void
    {
        $first = $this->register()->assertCreated();
        $id = $first->json('data.person.id');
        $this->actingAs($this->admin)->postJson('/api/admin/evacuation/events/'.$this->evacuation->id.'/close')->assertOk();
        $this->postJson('/api/admin/evacuation/events', ['name' => 'Next Test Event'])->assertCreated();
        $this->register(['evacuee_id' => $id])->assertCreated()->assertJsonPath('data.person.previous_evacuations', 1)->assertJsonPath('data.person.total_evacuations', 2)->assertJsonPath('data.center.current', 1)->assertJsonPath('data.center.returning', 1)->assertJsonPath('data.center.first_time', 0);
        $this->assertDatabaseCount('evacuation_registrations', 2);
    }

    public function test_manager_cannot_choose_an_arbitrary_center_event_or_timestamp(): void
    {
        foreach (['center_id', 'event_id', 'camp_manager_id', 'registered_at'] as $field) {
            $this->register([$field => 999])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('evacuation_registrations', 0);
    }

    public function test_unassigned_wrong_role_and_missing_sessions_are_denied(): void
    {
        $this->getJson('/api/camp/context')->assertUnauthorized();
        foreach (['admin', 'residents'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/camp/context')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'campmanager']))->getJson('/api/camp/context')->assertForbidden();
        $this->actingAs($this->manager)->getJson('/api/admin/evacuation/centers')->assertForbidden();
    }

    public function test_private_person_search_and_history_respect_assignment_scope(): void
    {
        $other = EvacuationPlace::create(['barangay' => 'Other Place', 'municipality' => 'Other Municipality', 'canonical_key' => 'other|place']);
        $person = Evacuee::create(['full_name' => 'Secret Person', 'place_id' => $other->id]);
        $this->actingAs($this->manager)->getJson('/api/camp/people?q=Secret')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/camp/people/'.$person->id)->assertNotFound();
        $this->register(['evacuee_id' => $person->id])->assertNotFound();
        $this->register(['place_id' => $other->id])->assertForbidden();
    }

    public function test_capacity_is_enforced_and_failed_registration_rolls_back_new_identity(): void
    {
        $this->center->update(['capacity' => 1]);
        $this->register()->assertCreated();
        $this->register(['full_name' => 'Another Person'])->assertConflict();
        $this->assertDatabaseCount('evacuees', 1);
        $this->assertDatabaseCount('evacuation_registrations', 1);
    }

    public function test_checkout_reentry_and_transfer_do_not_add_history_or_double_occupancy(): void
    {
        $first = $this->register()->assertCreated();
        $id = $first->json('data.registration.id');
        $this->patchJson('/api/camp/registrations/'.$id, ['action' => 'check_out'])->assertOk();
        $this->getJson('/api/camp/context')->assertJsonPath('data.center.current', 0)->assertJsonPath('data.center.registered', 1);
        $this->patchJson('/api/camp/registrations/'.$id, ['action' => 'check_in'])->assertOk();
        $target = EvacuationCenter::create(['name' => 'Other Test Center', 'place_id' => $this->place->id, 'capacity' => 10]);
        $this->patchJson('/api/camp/registrations/'.$id, ['action' => 'transfer', 'center_id' => $target->id])->assertForbidden();
        $this->actingAs($this->admin)->patchJson('/api/admin/evacuation/registrations/'.$id, ['action' => 'transfer', 'center_id' => $target->id])->assertOk();
        $this->getJson('/api/admin/evacuation/centers/'.$this->center->id)->assertJsonPath('data.current', 0)->assertJsonPath('data.total_recorded_events', 1);
        $this->getJson('/api/admin/evacuation/centers/'.$target->id)->assertJsonPath('data.current', 1);
        $this->assertDatabaseCount('evacuation_registrations', 1);
        $this->assertDatabaseCount('evacuation_movements', 4);
    }

    public function test_broadcasts_only_affected_center_after_commit_and_never_after_rollback(): void
    {
        Event::fake([EvacuationCenterUpdated::class]);
        $this->register()->assertCreated();
        Event::assertDispatched(EvacuationCenterUpdated::class, fn ($event) => $event->centerId === $this->center->id);
        Event::assertDispatchedTimes(EvacuationCenterUpdated::class, 1);
        Event::fake([EvacuationCenterUpdated::class]);
        $this->center->update(['capacity' => 1]);
        $this->register()->assertConflict();
        Event::assertNotDispatched(EvacuationCenterUpdated::class);
    }

    public function test_unknown_age_and_gender_are_counted_without_inventing_values(): void
    {
        $this->register(['reported_age' => null, 'gender' => 'unspecified'])->assertCreated()->assertJsonPath('data.center.demographics.age.unknown', 1)->assertJsonPath('data.center.demographics.gender.unspecified', 1);
    }

    public function test_age_group_boundaries_and_valid_birthdays(): void
    {
        foreach ([0 => 'infants', 2 => 'infants', 3 => 'children', 12 => 'children', 13 => 'teenagers', 17 => 'teenagers', 18 => 'adults', 59 => 'adults', 60 => 'seniors', 100 => 'seniors'] as $age => $group) {
            $this->assertSame($group, EvacuationDemographics::group($age));
        }
        $this->assertSame('unknown', EvacuationDemographics::group(null));
        $this->register(['date_of_birth' => now()->subYears(35)->toDateString(), 'reported_age' => null])->assertCreated()->assertJsonPath('data.registration.age', 35);
        $this->register(['date_of_birth' => now()->addDay()->toDateString()])->assertUnprocessable();
    }

    public function test_admin_can_link_resident_identity_without_duplicate_profiles(): void
    {
        $resident = User::factory()->create(['role' => 'residents']);
        $body = [...$this->body(), 'user_id' => $resident->id];
        unset($body['client_request_id']);
        $this->actingAs($this->admin)->postJson('/api/admin/evacuation/residents', $body)->assertOk();
        $this->postJson('/api/admin/evacuation/residents', $body)->assertOk();
        $this->assertDatabaseCount('evacuees', 1);
        $this->actingAs($this->manager)->getJson('/api/camp/people?q=Test')->assertJsonPath('data.0.user_id', $resident->id);
    }

    public function test_setup_uses_canonical_places_and_only_one_active_event(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/evacuation/places', ['barangay' => 'TEST BARANGAY', 'municipality' => 'TEST MUNICIPALITY'])->assertCreated();
        $this->postJson('/api/admin/evacuation/places', ['barangay' => 'test barangay', 'municipality' => 'test municipality'])->assertCreated();
        $this->assertDatabaseCount('evacuation_places', 2);
        $this->postJson('/api/admin/evacuation/events', ['name' => 'Illegal second active event'])->assertConflict();
    }

    public function test_expired_bearer_token_cannot_register_or_read_private_records(): void
    {
        $token = $this->manager->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/camp/context')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/camp/registrations', $this->body())->assertUnauthorized();
        $this->assertDatabaseCount('evacuation_registrations', 0);
    }

    public function test_demographics_and_place_filters_count_current_people_and_preserve_snapshots(): void
    {
        $first = $this->register(['reported_age' => 2])->assertCreated();
        $second = $this->register(['reported_age' => 60, 'gender' => 'other', 'purok' => 'Purok 2'])->assertCreated();
        $this->getJson('/api/camp/context')->assertJsonPath('data.center.demographics.places.0.total', 2)->assertJsonPath('data.center.demographics.age.infants', 1)->assertJsonPath('data.center.demographics.age.seniors', 1);
        $this->patchJson('/api/camp/registrations/'.$first->json('data.registration.id'), ['action' => 'check_out'])->assertOk();
        $this->getJson('/api/camp/context')->assertJsonPath('data.center.current', 1)->assertJsonPath('data.center.demographics.age.infants', 0)->assertJsonPath('data.center.demographics.gender.other', 1)->assertJsonPath('data.center.demographics.places.0.total', 1);
        Evacuee::findOrFail($second->json('data.person.id'))->update(['reported_age' => 61, 'purok' => 'Corrected residence']);
        $this->getJson('/api/camp/registrations?place_id='.$this->place->id.'&status=checked_in')->assertJsonCount(1, 'data')->assertJsonPath('data.0.age', 60)->assertJsonPath('data.0.purok', 'Purok 2');
    }

    public function test_websocket_failure_keeps_committed_registration_available_for_refresh(): void
    {
        Event::listen(EvacuationCenterUpdated::class, function () { throw new \RuntimeException('Simulated socket outage'); });
        $this->register()->assertCreated()->assertJsonPath('data.center.current', 1);
        $this->getJson('/api/camp/context')->assertOk()->assertJsonPath('data.center.registered', 1);
        $this->actingAs($this->admin)->getJson('/api/admin/evacuation/centers/'.$this->center->id)->assertOk()->assertJsonPath('data.current', 1)->assertJsonPath('data.demographics.age.adults', 1);
    }
}
