<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot(['status', 'is_default'])
            ->withTimestamps();
    }

    public function globalRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_global_roles');
    }

    public function branchMemberships(): HasMany
    {
        return $this->hasMany(BranchUser::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasGlobalRole('super_admin');
    }

    public function hasPermission(string $permission, ?int $branchId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleIds = DB::table('user_global_roles')->where('user_id', $this->getKey())->pluck('role_id')
            ->merge($branchId ? DB::table('branch_user_roles')->where('user_id', $this->getKey())->where('branch_id', $branchId)->pluck('role_id') : [])
            ->unique();

        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roleIds)->where('permissions.key', $permission)->exists();
    }

    public function hasGlobalRole(string $role): bool
    {
        return DB::table('user_global_roles')
            ->join('roles', 'roles.id', '=', 'user_global_roles.role_id')
            ->where('user_global_roles.user_id', $this->getKey())
            ->where('roles.key', $role)
            ->where('roles.scope', 'global')
            ->exists();
    }

    public function hasBranchRole(int $branchId, string $role): bool
    {
        return DB::table('branch_user_roles')
            ->join('branch_user', function ($join) {
                $join->on('branch_user.branch_id', '=', 'branch_user_roles.branch_id')
                    ->on('branch_user.user_id', '=', 'branch_user_roles.user_id');
            })
            ->join('roles', 'roles.id', '=', 'branch_user_roles.role_id')
            ->where('branch_user_roles.user_id', $this->getKey())
            ->where('branch_user_roles.branch_id', $branchId)
            ->where('branch_user.status', 'active')
            ->where('roles.key', $role)
            ->where('roles.scope', 'branch')
            ->exists();
    }

    public function belongsToBranch(int $branchId): bool
    {
        return DB::table('branch_user')
            ->where('user_id', $this->getKey())
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->exists();
    }

    public function accessibleBranches(): Builder
    {
        $query = Branch::query()->where('status', 'active');

        if (! $this->isSuperAdmin()) {
            $query->whereIn('branches.id', function ($subquery) {
                $subquery->select('branch_id')
                    ->from('branch_user')
                    ->where('user_id', $this->getKey())
                    ->where('status', 'active');
            });
        }

        return $query;
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
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
