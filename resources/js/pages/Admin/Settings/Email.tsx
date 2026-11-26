import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Mail, Send } from 'lucide-react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Email',
        href: '/admin/settings/email',
    },
];

interface EmailSettings {
    host: string;
    port: number;
    encryption: string;
    username: string;
    from_address: string;
    from_name: string;
    has_password: boolean;
}

interface Props {
    settings: EmailSettings;
    source: 'settings' | 'env';
    effective: {
        mailer: string;
        host: string;
        from_address: string;
    };
}

export default function EmailSettingsPage({ settings, source, effective }: Props) {
    const form = useForm({
        host: settings.host || '',
        port: settings.port || 587,
        encryption: settings.encryption || 'none',
        username: settings.username || '',
        password: '',
        from_address: settings.from_address || '',
        from_name: settings.from_name || '',
    });

    const testForm = useForm({
        email: settings.from_address || '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.put(route('admin.settings.email.update'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('password');
                toast.success('Pengaturan email disimpan');
            },
        });
    };

    const sendTest = (e: React.FormEvent) => {
        e.preventDefault();

        testForm.post(route('admin.settings.email.test'), {
            preserveScroll: true,
        });
    };

    const reset = () => {
        if (!confirm('Hapus pengaturan email dan kembali memakai konfigurasi .env?')) {
            return;
        }

        router.delete(route('admin.settings.email.reset'), {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email Settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Email Settings"
                        description="Configure outgoing mail (SMTP) so password resets, verification and notifications can be delivered."
                    />

                    <Alert>
                        <Mail className="h-4 w-4" />
                        <AlertTitle>{source === 'settings' ? 'Using settings from this page' : 'Using .env configuration'}</AlertTitle>
                        <AlertDescription>
                            Mailer <code>{effective.mailer}</code> via <code>{effective.host || '—'}</code>, sending as{' '}
                            <code>{effective.from_address || '—'}</code>.
                            {source === 'env'
                                ? ' Saving this form switches outgoing mail to the values below.'
                                : ' Clear the form to fall back to .env.'}
                        </AlertDescription>
                    </Alert>

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="host">SMTP host</Label>
                                <Input
                                    id="host"
                                    value={form.data.host}
                                    onChange={(e) => form.setData('host', e.target.value)}
                                    placeholder="smtp.mailgun.org"
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.host} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="port">Port</Label>
                                <Input
                                    id="port"
                                    type="number"
                                    min={1}
                                    max={65535}
                                    value={form.data.port}
                                    onChange={(e) => form.setData('port', Number(e.target.value))}
                                />
                                <InputError message={form.errors.port} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="encryption">Encryption</Label>
                                <Select value={form.data.encryption} onValueChange={(value) => form.setData('encryption', value)}>
                                    <SelectTrigger id="encryption">
                                        <SelectValue placeholder="Select encryption" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tls">TLS (recommended, port 587)</SelectItem>
                                        <SelectItem value="ssl">SSL (port 465)</SelectItem>
                                        <SelectItem value="none">None</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.encryption} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    value={form.data.username}
                                    onChange={(e) => form.setData('username', e.target.value)}
                                    placeholder="postmaster@example.com"
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.username} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                placeholder={settings.has_password ? '••••••••  (leave blank to keep the current password)' : ''}
                                autoComplete="new-password"
                            />
                            <p className="text-muted-foreground text-xs">Stored encrypted. The saved password is never sent back to this page.</p>
                            <InputError message={form.errors.password} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="from_address">From address</Label>
                                <Input
                                    id="from_address"
                                    type="email"
                                    value={form.data.from_address}
                                    onChange={(e) => form.setData('from_address', e.target.value)}
                                    placeholder="hello@example.com"
                                />
                                <InputError message={form.errors.from_address} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="from_name">From name</Label>
                                <Input
                                    id="from_name"
                                    value={form.data.from_name}
                                    onChange={(e) => form.setData('from_name', e.target.value)}
                                    placeholder="My Website"
                                />
                                <InputError message={form.errors.from_name} />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                                Save settings
                            </Button>
                            <Button type="button" variant="outline" onClick={reset}>
                                Reset to .env
                            </Button>
                        </div>
                    </form>

                    <Card>
                        <CardHeader>
                            <CardTitle>Send a test email</CardTitle>
                            <CardDescription>Uses the saved settings. Save the form first if you changed anything.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={sendTest} className="flex flex-wrap items-end gap-3">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="test_email">Recipient</Label>
                                    <Input
                                        id="test_email"
                                        type="email"
                                        value={testForm.data.email}
                                        onChange={(e) => testForm.setData('email', e.target.value)}
                                        placeholder="you@example.com"
                                    />
                                    <InputError message={testForm.errors.email} />
                                </div>
                                <Button type="submit" variant="secondary" disabled={testForm.processing}>
                                    {testForm.processing ? <LoaderCircle className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                    Send test
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
