<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'contact_number', 'office', 'jurisdiction', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public function campAssignment() { return $this->hasOne(CampAssignment::class); }
    public function evacueeProfile() { return $this->hasOne(Evacuee::class); }
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CAMP_MANAGER = 'campmanager';

    public const ROLE_RESIDENT = 'residents';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Determine whether the user holds one of the given roles.
     *
     * @param  list<string>  $roles
     */
    public function hasRoleIn(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
