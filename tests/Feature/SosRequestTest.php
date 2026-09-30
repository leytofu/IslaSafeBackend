<?php

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SosRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeSosRequest(array $attributes = []): SosRequest
    {
        return SosRequest::create(array_merge([
            'name' => 'Maria Dela Cruz',
            'contact' => '0917-XXX-1208',
            'location' => 'Purok 2, Pitogo',
            'latitude' => 10.118,
            'longitude' => 124.561,
            'type' => 'Medical emergency',
            'category' => 'Medical',
            'priority' => 'Critical',
            'description' => 'Elderly resident experiencing chest pain.',
            'status' => 'Pending',
            'received_at' => now()->subHour(),
        ], $attributes));
    }

    public function test_listing_sos_requests_requires_authentication(): void
    {
        $this->getJson('/api/sos-requests')->assertUnauthorized();
    }

    public function test_residents_role_cannot_list_sos_requests(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_RESIDENT), 'sanctum')
            ->getJson('/api/sos-requests')
            ->assertForbidden();
    }

    public function test_admin_sees_requests_newest_first_with_generated_codes(): void
    {
        $older = $this->makeSosRequest(['received_at' => now()->subDays(3)]);
        $newer = $this->makeSosRequest(['name' => 'Rogelio Ramos', 'received_at' => now()]);

        $response = $this->actingAs($this->makeUser(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/sos-requests');

        $response->assertOk()->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertMatchesRegularExpression('/^SOS-\d{4}-\d{4}$/', $newer->id);
    }

    public function test_anyone_can_submit_an_emergency_request(): void
    {
        $response = $this->postJson('/api/sos-requests', [
            'name' => 'New SOS',
            'contact' => '0917-000-0000',
            'location' => 'Purok 6, Pitogo',
            'latitude' => 10.121,
            'longitude' => 124.558,
            'type' => 'Emergency assistance',
            'category' => 'Medical',
            'priority' => 'High',
            'description' => 'A newly received request is awaiting dispatch review.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.category', 'Medical');

        $this->assertDatabaseHas('sos_requests', ['name' => 'New SOS']);
    }

    public function test_submitting_invalid_request_is_rejected(): void
    {
        $this->postJson('/api/sos-requests', ['name' => 'Missing fields'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact', 'latitude', 'category', 'priority']);
    }

    public function test_admin_can_update_sos_status(): void
    {
        $sos = $this->makeSosRequest();

        $this->actingAs($this->makeUser(User::ROLE_ADMIN), 'sanctum')
            ->patchJson("/api/sos-requests/{$sos->id}", ['status' => 'Coming'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Coming');

        $this->assertDatabaseHas('sos_requests', ['id' => $sos->id, 'status' => 'Coming']);
    }

    public function test_camp_manager_can_update_sos_status(): void
    {
        $sos = $this->makeSosRequest();

        $this->actingAs($this->makeUser(User::ROLE_CAMP_MANAGER), 'sanctum')
            ->patchJson("/api/sos-requests/{$sos->id}", ['status' => 'Resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Resolved');
    }

    public function test_residents_cannot_update_sos_status(): void
    {
        $sos = $this->makeSosRequest();

        $this->actingAs($this->makeUser(User::ROLE_RESIDENT), 'sanctum')
            ->patchJson("/api/sos-requests/{$sos->id}", ['status' => 'Resolved'])
            ->assertForbidden();
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $sos = $this->makeSosRequest();

        $this->actingAs($this->makeUser(User::ROLE_ADMIN), 'sanctum')
            ->patchJson("/api/sos-requests/{$sos->id}", ['status' => 'Nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
