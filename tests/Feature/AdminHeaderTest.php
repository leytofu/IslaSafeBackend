<?php

namespace Tests\Feature;

use App\Models\EvacuationCenter;
use App\Models\EvacuationPlace;
use App\Models\Incident;
use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function sos(array $attributes = []): SosRequest
    {
        return SosRequest::create(array_merge([
            'name' => 'Maria Pitogo', 'contact' => '09170000000', 'location' => 'Pitogo coast',
            'latitude' => 10.1, 'longitude' => 124.5, 'type' => 'Medical emergency', 'category' => 'Medical',
            'priority' => 'Critical', 'description' => 'Needs assistance', 'status' => 'Pending', 'received_at' => now(),
        ], $attributes));
    }

    private function incident(): Incident
    {
        return Incident::create(['type' => 'Typhoon', 'location' => 'Pitogo coast', 'severity' => 'High',
            'status' => 'Monitoring', 'reported_at' => now()]);
    }

    public function test_header_endpoints_are_admin_only(): void
    {
        $this->getJson('/api/admin/search?q=Pitogo')->assertUnauthorized();
        $this->getJson('/api/admin/notifications')->assertUnauthorized();
        $this->postJson('/api/admin/notifications/read', ['keys' => [str_repeat('a', 64)]])->assertUnauthorized();
        foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/admin/search?q=Pitogo')->assertForbidden();
            $this->getJson('/api/admin/notifications')->assertForbidden();
            $this->postJson('/api/admin/notifications/read', ['keys' => [str_repeat('a', 64)]])->assertForbidden();
        }
    }

    public function test_search_finds_real_records_by_name_id_and_barangay_without_exposing_credentials(): void
    {
        $this->admin();
        $sos = $this->sos();
        $incident = $this->incident();
        $place = EvacuationPlace::create(['barangay' => 'Pitogo', 'municipality' => 'CPG', 'canonical_key' => 'pitogo']);
        EvacuationCenter::create(['name' => 'Community hall', 'place_id' => $place->id]);
        $resident = User::factory()->create(['role' => User::ROLE_RESIDENT, 'name' => 'Resident Test', 'barangay' => 'Pitogo', 'address' => 'Purok 2']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Pitogo private admin']);
        $response = $this->getJson('/api/admin/search?q=pItOgO')->assertOk()->assertJsonCount(4, 'data');
        $this->assertEqualsCanonicalizing(['sos', 'incident', 'center', 'resident'], array_column($response->json('data'), 'kind'));
        $response->assertDontSee('password')->assertDontSee('remember_token')->assertDontSee('Pitogo private admin');
        $this->getJson('/api/admin/search?q='.urlencode($sos->id))->assertJsonPath('data.0.id', $sos->id);
        $this->getJson('/api/admin/search?q=INC-'.str_pad($incident->id, 4, '0', STR_PAD_LEFT))->assertJsonPath('data.0.kind', 'incident');
        $this->getJson('/api/admin/search?q='.urlencode($resident->email))->assertJsonPath('data.0.kind', 'resident');
    }

    public function test_search_treats_wildcards_literally_and_validates_query_length(): void
    {
        $this->admin();
        $this->sos();
        $this->getJson('/api/admin/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/search?q=__')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/search?q=a')->assertUnprocessable();
        $this->getJson('/api/admin/search?q='.str_repeat('a', 121))->assertUnprocessable();
    }

    public function test_notification_reads_persist_per_admin_and_new_updates_become_unread(): void
    {
        $first = $this->admin();
        $sos = $this->sos();
        $this->incident();
        $feed = $this->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('unread_count', 2);
        $keys = array_column($feed->json('data'), 'key');
        $this->postJson('/api/admin/notifications/read', ['keys' => [$keys[0]]])->assertOk()->assertJsonPath('unread_count', 1);
        $this->getJson('/api/admin/notifications')->assertJsonPath('unread_count', 1);
        $this->postJson('/api/admin/notifications/read', ['keys' => $keys])->assertJsonPath('unread_count', 0);
        $this->assertDatabaseCount('admin_activity_reads', 2);
        $this->admin();
        $this->getJson('/api/admin/notifications')->assertJsonPath('unread_count', 2);
        $this->actingAs($first, 'sanctum');
        $sos->update(['status' => 'Coming']);
        $this->getJson('/api/admin/notifications')->assertJsonPath('unread_count', 1);
    }

    public function test_mark_all_reads_only_supplied_events_and_does_not_modify_sos_status(): void
    {
        $this->admin();
        $sos = $this->sos();
        $keys = array_column($this->getJson('/api/admin/notifications')->json('data'), 'key');
        $this->incident();
        $this->postJson('/api/admin/notifications/read', ['keys' => $keys])->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertSame('Pending', $sos->fresh()->status);
        $this->postJson('/api/admin/notifications/read', ['keys' => [str_repeat('f', 64)]])->assertOk();
        $this->assertDatabaseCount('admin_activity_reads', 1);
        $this->postJson('/api/admin/notifications/read', ['keys' => []])->assertUnprocessable();
    }

    public function test_empty_feed_has_no_fake_unread_badge(): void
    {
        $this->admin();
        $this->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');
    }
}
