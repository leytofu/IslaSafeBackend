<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-15T12:00:00Z'));
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum');
    }

    private function report(array $overrides = []): array
    {
        return array_merge([
            'type' => 'Typhoon', 'location' => 'Pitogo', 'severity' => 'High',
            'status' => 'Active', 'affected' => '12 households',
            'description' => 'Coastal flooding reported.', 'reported_at' => '2026-10-14T09:00:00+08:00',
        ], $overrides);
    }

    public function test_incident_routes_require_an_admin(): void
    {
        $this->getJson('/api/admin/incidents/statistics')->assertUnauthorized();
        $this->postJson('/api/admin/incidents', $this->report())->assertUnauthorized();
        $incident = Incident::create($this->report());
        foreach ([User::ROLE_RESIDENT, User::ROLE_CAMP_MANAGER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/admin/incidents')->assertForbidden();
            $this->getJson('/api/admin/incidents/statistics')->assertForbidden();
            $this->postJson('/api/admin/incidents', $this->report())->assertForbidden();
            $this->patchJson('/api/admin/incidents/'.$incident->id, $this->report())->assertForbidden();
        }
    }

    public function test_logging_and_resolving_an_incident_updates_statistics_and_keeps_history(): void
    {
        $this->admin();
        $created = $this->postJson('/api/admin/incidents', $this->report(['reported_by' => 999]))
            ->assertCreated()->assertJsonPath('data.type', 'Typhoon');
        $id = $created->json('data.id');
        $this->assertDatabaseHas('incidents', ['id' => $id, 'reported_by' => auth()->id()]);
        $this->getJson('/api/admin/incidents/statistics?period=week')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.active', 1)->assertJsonPath('data.active_all', 1)
            ->assertJsonPath('data.breakdown.0.count', 1);
        $this->patchJson('/api/admin/incidents/'.$id, $this->report(['status' => 'Resolved']))->assertOk();
        $this->getJson('/api/admin/incidents/statistics?period=week')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.active', 0)->assertJsonPath('data.resolved', 1)
            ->assertJsonPath('data.active_all', 0);
        $this->getJson('/api/admin/incidents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'Resolved');
    }

    public function test_periods_use_philippine_calendar_boundaries_and_include_all_statuses_once(): void
    {
        $this->admin();
        foreach ([
            ['2026-10-11T16:00:00Z', 'Active', 'Typhoon'], // Monday midnight in Manila.
            ['2026-10-11T15:59:59Z', 'Resolved', 'Intense rains'],
            ['2026-10-14T10:00:00Z', 'Monitoring', 'Typhoon'],
            ['2026-09-30T16:00:00Z', 'Closed', 'Strong winds'], // October starts in Manila.
            ['2026-09-30T15:59:59Z', 'Responding', 'Storm surge'],
            ['2025-12-31T16:00:00Z', 'Resolved', 'Landslide'], // 2026 starts in Manila.
            ['2025-12-31T15:59:59Z', 'Active', 'Others'],
        ] as [$date, $status, $type]) {
            Incident::create($this->report(['reported_at' => \Carbon\Carbon::parse($date), 'status' => $status, 'type' => $type]));
        }
        $this->getJson('/api/admin/incidents/statistics?period=week')->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.active', 2)->assertJsonPath('data.breakdown.0.count', 2)
            ->assertJsonPath('data.active_all', 4)->assertJsonPath('data.timezone', 'Asia/Manila');
        $this->getJson('/api/admin/incidents/statistics?period=month')->assertJsonPath('data.total', 4);
        $this->getJson('/api/admin/incidents/statistics?period=year')->assertJsonPath('data.total', 6);
        $all = $this->getJson('/api/admin/incidents/statistics?period=all')->assertJsonPath('data.total', 7)->assertJsonPath('data.from', null);
        $this->assertSame(7, array_sum(array_column($all->json('data.breakdown'), 'count')));
        $this->assertSame(7, $all->json('data.active') + $all->json('data.resolved'));
    }

    public function test_empty_statistics_return_real_zeros(): void
    {
        $this->admin();
        $response = $this->getJson('/api/admin/incidents/statistics')->assertOk()->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.active_all', 0)->assertJsonCount(6, 'data.breakdown');
        $this->assertSame([0, 0, 0, 0, 0, 0], array_column($response->json('data.breakdown'), 'count'));
    }

    public function test_invalid_period_future_dates_and_unknown_categories_are_rejected(): void
    {
        $this->admin();
        $this->getJson('/api/admin/incidents/statistics?period=invalid')->assertUnprocessable();
        $this->postJson('/api/admin/incidents', $this->report(['reported_at' => '2026-10-16T12:00:00Z']))
            ->assertUnprocessable()->assertJsonValidationErrors('reported_at');
        $this->postJson('/api/admin/incidents', $this->report(['type' => 'invented', 'status' => 'invented']))
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'status']);
        $this->assertDatabaseCount('incidents', 0);
    }
}
