import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import HeadingSmall from '@/components/heading-small';
import SettingsLayout from '@/layouts/settings/layout';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'General Settings',
        href: '/settings/general',
    },
];

interface GeneralSettingsProps {
    settings: {
        site_name: string;
        site_description: string;
        site_logo: string;
        site_favicon: string;
        registration_enabled: boolean;
        registration_default_role: string;
        member_redirect: string;
        store_url: string;
        store_enabled: boolean;
    };
    roles: string[];
}

export default function GeneralSettings({ settings, roles }: GeneralSettingsProps) {
    const { data, setData, put, processing, errors } = useForm({
        settings: {
            site_name: settings.site_name || '',
            site_description: settings.site_description || '',
            site_logo: settings.site_logo || '',
            site_favicon: settings.site_favicon || '',
            registration_enabled: Boolean(settings.registration_enabled),
            registration_default_role: settings.registration_default_role || '',
            member_redirect: settings.member_redirect || '/',
            store_url: settings.store_url || '',
            store_enabled: Boolean(settings.store_enabled),
        },
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.settings.update', 'general'), {
            onSuccess: () => toast.success('Pengaturan umum berhasil diperbarui'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="General Settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="General Settings"
                        description="Basic website settings like name, description, and logo"
                    />

                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="site_name">Website Name</Label>
                            <Input
                                id="site_name"
                                type="text"
                                value={data.settings.site_name}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        site_name: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="Website Name"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="site_description">Website Description</Label>
                            <Textarea
                                id="site_description"
                                value={data.settings.site_description}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        site_description: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="Website Description"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="site_logo">Website Logo URL</Label>
                            <Input
                                id="site_logo"
                                type="text"
                                value={data.settings.site_logo}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        site_logo: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="Website Logo URL"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="site_favicon">Website Favicon URL</Label>
                            <Input
                                id="site_favicon"
                                type="text"
                                value={data.settings.site_favicon}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        site_favicon: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="Website Favicon URL"
                            />
                        </div>

                        <div className="border-border border-t pt-6">
                            <h3 className="text-lg font-medium">Package Store</h3>
                            <p className="text-muted-foreground text-sm">
                                Sumber paket plugin dan theme yang bisa dipasang dari Admin → Marketplace.
                            </p>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="store_enabled">Aktifkan Marketplace</Label>
                                <p className="text-muted-foreground text-sm">
                                    Matikan untuk instalasi tanpa akses internet atau yang dikelola manual.
                                </p>
                            </div>
                            <Switch
                                id="store_enabled"
                                checked={data.settings.store_enabled}
                                onCheckedChange={(checked) =>
                                    setData('settings', {
                                        ...data.settings,
                                        store_enabled: checked,
                                    })
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="store_url">Store URL</Label>
                            <Input
                                id="store_url"
                                type="url"
                                value={data.settings.store_url}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        store_url: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="https://nebula.angkasalabs.com"
                            />
                            <p className="text-muted-foreground text-xs">
                                Alamat registry paket. Kosongkan untuk memakai nilai dari konfigurasi.
                            </p>
                            <InputError message={errors['settings.store_url']} />
                        </div>

                        <div className="border-border border-t pt-6">
                            <h3 className="text-lg font-medium">Registrasi &amp; Akses</h3>
                            <p className="text-muted-foreground text-sm">
                                Kontrol siapa yang boleh mendaftar dan ke mana pengguna tanpa akses admin diarahkan setelah masuk.
                            </p>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="registration_enabled">Izinkan pendaftaran publik</Label>
                                <p className="text-muted-foreground text-sm">
                                    Jika dimatikan, halaman /register tidak dapat diakses. Default: mati.
                                </p>
                            </div>
                            <Switch
                                id="registration_enabled"
                                checked={data.settings.registration_enabled}
                                onCheckedChange={(checked) =>
                                    setData('settings', {
                                        ...data.settings,
                                        registration_enabled: checked,
                                    })
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="registration_default_role">Role default pengguna baru</Label>
                            <Select
                                value={data.settings.registration_default_role || 'none'}
                                onValueChange={(value) =>
                                    setData('settings', {
                                        ...data.settings,
                                        registration_default_role: value === 'none' ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger id="registration_default_role" className="w-full">
                                    <SelectValue placeholder="Tanpa role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Tanpa role (hanya pengguna biasa)</SelectItem>
                                    {roles.map((role) => (
                                        <SelectItem key={role} value={role}>
                                            {role}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                Hati-hati: role yang memiliki izin "access admin" akan bisa masuk panel admin.
                            </p>
                            <InputError message={errors['settings.registration_default_role']} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="member_redirect">Halaman tujuan pengguna non-admin</Label>
                            <Input
                                id="member_redirect"
                                type="text"
                                value={data.settings.member_redirect}
                                onChange={(e) =>
                                    setData('settings', {
                                        ...data.settings,
                                        member_redirect: e.target.value,
                                    })
                                }
                                className="mt-1 block w-full"
                                placeholder="/"
                            />
                            <p className="text-muted-foreground text-xs">
                                Harus berupa path lokal yang diawali garis miring, misalnya / atau /akun.
                            </p>
                            <InputError message={errors['settings.member_redirect']} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button type="submit" disabled={processing}>
                                Save Settings
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
