<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_updating_profile_requires_authentication(): void
    {
        $this->patchJson('/api/auth/profile', ['name' => 'No Token'])->assertUnauthorized();
    }

    public function test_user_can_update_profile_fields(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => 'Juan Admin Dela Cruz',
                'email' => $user->email,
                'contact_number' => '+63 912 345 6789',
                'office' => 'MDRRMO Office, Carlos P. Garcia',
                'jurisdiction' => 'CPG, Bohol — 23 barangays',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Juan Admin Dela Cruz')
            ->assertJsonPath('user.contact_number', '+63 912 345 6789')
            ->assertJsonPath('user.office', 'MDRRMO Office, Carlos P. Garcia')
            ->assertJsonPath('user.jurisdiction', 'CPG, Bohol — 23 barangays');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Juan Admin Dela Cruz',
            'contact_number' => '+63 912 345 6789',
        ]);
    }

    public function test_user_can_change_their_own_email(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => 'new.admin@cpg.gov.ph',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'new.admin@cpg.gov.ph');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new.admin@cpg.gov.ph']);
    }

    public function test_email_must_be_unique_among_other_users(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => $other->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_avatar_upload_is_stored_and_url_returned(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        // Minimal valid 1x1 PNG — avoids the GD extension requirement of
        // UploadedFile::fake()->image() while still exercising real MIME checks.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->createWithContent('avatar.png', $png),
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('user.avatar_url'));

        $user->refresh();
        $this->assertNotNull($user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    public function test_oversized_or_invalid_avatar_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->create('doc.pdf', 300, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_profile_update_cannot_change_role_or_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'role' => User::ROLE_RESIDENT,
                'password' => 'hacked-password-123',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertNotSame('hacked-password-123', $user->password);
    }

    public function test_validation_rejects_missing_required_fields(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/auth/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_me_returns_extended_profile_payload(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'contact_number' => '0917-000-0000',
            'office' => 'MDRRMO',
            'jurisdiction' => 'CPG, Bohol',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'contact_number', 'office', 'jurisdiction', 'avatar_url']])
            ->assertJsonPath('user.contact_number', '0917-000-0000')
            ->assertJsonPath('user.avatar_url', null);
    }
}
