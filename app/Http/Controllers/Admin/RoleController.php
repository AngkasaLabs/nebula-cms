<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ContentSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage roles');
    }

    public function index()
    {
        $roles = Role::with('permissions')
            ->withCount('users')
            ->when(request()->filled('search'), function ($query) {
                ContentSearch::applyLikeColumns($query, request()->string('search')->toString(), ['name']);
            })
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'filters' => request()->only('search'),
        ]);
    }

    public function create()
    {
        $permissions = Permission::orderBy('group')->get();

        return Inertia::render('Admin/Roles/Create', [
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        /** @var Role $role */
        $role = Role::create([
            'name' => $validated['name'],
        ]);

        $role->permissions()->sync($validated['permissions']);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($role)
                ->withProperties(['permissions' => array_values($validated['permissions'])])
                ->log('role_created');
        }

        return redirect()->route('admin.roles.index')
            ->with('message', 'Peran berhasil dibuat');
    }

    public function edit(Role $role)
    {
        $permissions = Permission::orderBy('group')->get();

        return Inertia::render('Admin/Roles/Edit', [
            'role' => $role->load('permissions'),
            'permissions' => $permissions,
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,'.$role->id,
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->update([
            'name' => $validated['name'],
        ]);

        $role->permissions()->sync($validated['permissions']);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($role)
                ->withProperties(['permissions' => array_values($validated['permissions'])])
                ->log('role_updated');
        }

        return redirect()->route('admin.roles.index')
            ->with('message', 'Peran berhasil diperbarui');
    }

    public function destroy(Role $role)
    {
        $deleted = ['id' => $role->id, 'name' => $role->name];

        $role->delete();

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties($deleted)
                ->log('role_deleted');
        }

        return redirect()->route('admin.roles.index')
            ->with('message', 'Peran berhasil dihapus');
    }
}
