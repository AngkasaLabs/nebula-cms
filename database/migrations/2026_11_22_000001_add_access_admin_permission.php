<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Gate the whole /admin area behind a single permission.
     *
     * Individual controllers still guard their own actions, but this permission
     * ensures accounts that were never meant to enter the admin panel (e.g.
     * store developers or site members) are stopped at the door.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => 'access admin', 'guard_name' => 'web'],
            ['group' => 'Administration']
        );

        if ($permission->group !== 'Administration') {
            $permission->group = 'Administration';
            $permission->save();
        }

        // Existing staff roles keep working after the upgrade.
        foreach (['Super Admin', 'Admin', 'Editor', 'Author'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'access admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return;
        }

        foreach (Role::all() as $role) {
            if ($role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }

        $permission->delete();
    }
};
