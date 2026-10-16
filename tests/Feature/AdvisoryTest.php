<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'Coastal evacuation', 'message' => 'Proceed to the designated center.', 'priority' => 'Critical', 'target' => 'Lapinig', 'status' => 'Active'], $overrides);
    }

    public function test_only_admins_can_manage_advisories(): void
    {
        $this->getJson('/api/admin/advisories')->assertUnauthorized();
        $this->postJson('/api/admin/advisories', $this->payload())->assertUnauthorized();
        $record = Advisory::create($this->payload(['published_at' => now()]));
        foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/admin/advisories')->assertForbidden();
            $this->postJson('/api/admin/advisories', $this->payload())->assertForbidden();
            $this->patchJson('/api/admin/advisories/'.$record->id, $this->payload())->assertForbidden();
        }
    }

    public function test_publish_edit_archive_restore_and_history_are_persisted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin, 'sanctum');
        $created = $this->postJson('/api/admin/advisories', $this->payload(['published_by' => 999, 'published_at' => '2000-01-01']))
            ->assertCreated()->assertJsonPath('data.status', 'Active');
        $id = $created->json('data.id');
        $published = $created->json('data.published_at');
        $this->assertDatabaseHas('advisories', ['id' => $id, 'published_by' => $admin->id]);
        $this->travel(1)->hours();
        $this->patchJson('/api/admin/advisories/'.$id, $this->payload(['title' => 'Updated notice']))->assertOk()
            ->assertJsonPath('data.title', 'Updated notice')->assertJsonPath('data.published_at', $published);
        $this->patchJson('/api/admin/advisories/'.$id, $this->payload(['status' => 'Archived']))->assertOk();
        $this->getJson('/api/admin/advisories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Archived');
        $restored = $this->patchJson('/api/admin/advisories/'.$id, $this->payload())->assertOk()->assertJsonPath('data.status', 'Active');
        $this->assertNotEquals($published, $restored->json('data.published_at'));
    }

    public function test_validation_and_missing_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum');
        $this->postJson('/api/admin/advisories', $this->payload(['title' => ' ', 'message' => '', 'target' => '', 'priority' => 'Urgent', 'status' => 'Deleted']))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'message', 'target', 'priority', 'status']);
        $this->postJson('/api/admin/advisories', $this->payload(['title' => str_repeat('x', 201), 'message' => str_repeat('x', 5001), 'target' => str_repeat('x', 201)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'message', 'target']);
        $this->patchJson('/api/admin/advisories/9999', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('advisories', 0);
    }
}
