import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { toast } from 'sonner';

interface Permission {
    id: number;
    name: string;
    group: string;
}

interface Role {
    id: number;
    name: string;
    permissions: Permission[];
}

interface Props {
    role?: Role;
    permissions: Permission[];
}

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Roles',
        href: route('admin.roles.index'),
    },
    {
        title: 'Create',
    },
];

export default function Form({ role, permissions }: Props) {
    const [values, setValues] = useState({
        name: role?.name || '',
        permissions: role?.permissions.map((p) => p.id) || [],
    });

    const [isSubmitting, setIsSubmitting] = useState(false);

    // Group permissions by their group name
    const groupedPermissions = permissions.reduce((groups: { [key: string]: Permission[] }, permission) => {
        const group = permission.group || 'Other';
        if (!groups[group]) {
            groups[group] = [];
        }
        groups[group].push(permission);
        return groups;
    }, {});

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        const submitData = {
            name: values.name,
            permissions: values.permissions,
        };

        if (role) {
            router.put(route('admin.roles.update', { role: role.id }), submitData, {
                onSuccess: () => {
                    toast.success('Role berhasil diperbarui');
                    setIsSubmitting(false);
                },
                onError: () => {
                    toast.error('Gagal memperbarui role');
                    setIsSubmitting(false);
                },
            });
        } else {
            router.post(route('admin.roles.store'), submitData, {
                onSuccess: () => {
                    toast.success('Role berhasil dibuat');
                    setIsSubmitting(false);
                },
                onError: () => {
                    toast.error('Gagal membuat role');
                    setIsSubmitting(false);
                },
            });
        }
    };

    const togglePermission = (permissionId: number) => {
        setValues((prev) => ({
            ...prev,
            permissions: prev.permissions.includes(permissionId)
                ? prev.permissions.filter((id) => id !== permissionId)
                : [...prev.permissions, permissionId],
        }));
    };

    const togglePermissionGroup = (groupPermissions: Permission[]) => {
        const permissionIds = groupPermissions.map((p) => p.id);
        const allSelected = groupPermissions.every((p) => values.permissions.includes(p.id));

        setValues((prev) => ({
            ...prev,
            permissions: allSelected
                ? prev.permissions.filter((id) => !permissionIds.includes(id))
                : [...new Set([...prev.permissions, ...permissionIds])],
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={role ? 'Edit Role' : 'Create Role'} />

            <div className="p-4">
                <form onSubmit={handleSubmit}>
                    <div className="mb-6 flex items-center justify-between">
                        <div>
                            <Link href={route('admin.roles.index')} className="mb-4 inline-flex items-center">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Return to roles list
                            </Link>
                            <h1 className="text-2xl font-bold">{role ? 'Edit Role' : 'Create New Role'}</h1>
                        </div>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Saving...' : role ? 'Update Role' : 'Save'}
                        </Button>
                    </div>

                    <div className="grid grid-cols-1 gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Role Information</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Role Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={values.name}
                                        onChange={(e) => setValues({ ...values, name: e.target.value })}
                                        placeholder="Enter role name"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Permissions</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {Object.entries(groupedPermissions).map(([group, groupPermissions]) => (
                                        <div key={group} className="overflow-hidden rounded border">
                                            <div className="flex items-center bg-gray-50 p-4">
                                                <Checkbox
                                                    checked={groupPermissions.every((p) => values.permissions.includes(p.id))}
                                                    onCheckedChange={() => togglePermissionGroup(groupPermissions)}
                                                    className="border-gray-700"
                                                />
                                                <span className="ml-3 font-medium">{group}</span>
                                            </div>
                                            <div className="grid grid-cols-1 gap-4 p-4 md:grid-cols-2 lg:grid-cols-3">
                                                {groupPermissions.map((permission) => (
                                                    <div key={permission.id} className="flex items-center">
                                                        <Checkbox
                                                            checked={values.permissions.includes(permission.id)}
                                                            onCheckedChange={() => togglePermission(permission.id)}
                                                            className="border-gray-700"
                                                        />
                                                        <span className="ml-3">{permission.name}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
