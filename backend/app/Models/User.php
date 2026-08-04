<?php

namespace App\Models;

use App\Support\BusinessRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'status',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
        ];
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('restaurant_id')
            ->withTimestamps();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\ResetPassword($token));
    }

    public function restaurantUsers(): HasMany
    {
        return $this->hasMany(RestaurantUser::class);
    }

    public function businessUsers(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function branchUsers(): HasMany
    {
        return $this->hasMany(BranchUser::class);
    }

    public function mfaMethod(): HasOne
    {
        return $this->hasOne(MfaMethod::class);
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class);
    }

    public function trackedSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->slug === $slug);
    }

    public function hasAnyRole(array $slugs): bool
    {
        return $this->roles->contains(fn (Role $role) => in_array($role->slug, $slugs, true));
    }

    public function hasPermission(string $slug): bool
    {
        return $this->roles->flatMap->permissions->contains(fn (Permission $p) => $p->slug === $slug);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isRestaurantUser(): bool
    {
        return $this->hasAnyRole([
            'restaurant_owner',
            'restaurant_manager',
            'restaurant_staff',
        ]);
    }

    public function isActiveAccount(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked'
            || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'disabled'], true);
    }

    public function hasConfirmedMfa(): bool
    {
        return (bool) ($this->mfaMethod?->is_confirmed
            ?? $this->mfaMethod()->where('is_confirmed', true)->exists());
    }

    public function primaryRestaurantId(): ?int
    {
        $assignment = $this->restaurantUsers()
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        return $assignment?->restaurant_id;
    }

    public function primaryRestaurantPublicId(): ?string
    {
        $assignment = $this->restaurantUsers()
            ->where('status', 'active')
            ->with('restaurant:id,public_id')
            ->orderBy('id')
            ->first();

        return $assignment?->restaurant?->public_id;
    }

    public function belongsToBusiness(int $businessId): bool
    {
        return $this->businessUsers()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->exists();
    }

    public function canAccessBusiness(int $businessId): bool
    {
        return $this->isSuperAdmin() || $this->belongsToBusiness($businessId);
    }

    public function belongsToBranch(int $branchId): bool
    {
        return $this->branchUsers()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->exists();
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            return false;
        }

        $businessRole = $this->businessUsers()
            ->where('business_id', $branch->business_id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($businessRole, array_merge(BusinessRoles::businessManagers(), [BusinessRoles::ACCOUNTANT]), true)) {
            return true;
        }

        return $this->belongsToBranch($branchId);
    }
}
