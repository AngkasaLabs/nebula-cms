<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Only roles with `unfiltered_html` may store editor HTML without server-side
     * sanitisation. Existing installs get the permission for Admin and Super Admin.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => 'unfiltered_html', 'guard_name' => 'web'],
            ['group' => 'Security']
        );

        foreach (['Super Admin', 'Admin'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

            if ($role) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Role::all() as $role) {
            $role->revokePermissionTo('unfiltered_html');
        }

        Permission::where('name', 'unfiltered_html')->delete();
    }
};
