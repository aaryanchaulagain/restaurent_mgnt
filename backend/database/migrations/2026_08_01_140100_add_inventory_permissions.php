<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['view_inventory', 'manage_inventory'] as $slug) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug)],
            );
        }

        $ids = Permission::query()
            ->whereIn('slug', ['view_inventory', 'manage_inventory'])
            ->pluck('id');

        foreach (['restaurant_owner', 'restaurant_manager', 'restaurant_staff'] as $roleSlug) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        }
    }

    public function down(): void
    {
        $ids = Permission::query()
            ->whereIn('slug', ['view_inventory', 'manage_inventory'])
            ->pluck('id');

        foreach (['restaurant_owner', 'restaurant_manager', 'restaurant_staff'] as $roleSlug) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if ($role) {
                $role->permissions()->detach($ids);
            }
        }

        Permission::query()->whereIn('slug', ['view_inventory', 'manage_inventory'])->delete();
    }
};
