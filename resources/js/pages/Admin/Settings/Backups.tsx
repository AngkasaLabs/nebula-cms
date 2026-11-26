import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Database, Download, HardDrive, LoaderCircle, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Backups',
        href: '/admin/settings/backups',
    },
];

interface Backup {
    filename: string;
    size: number;
    created_at: string;
    type: string;
    app_version: string;
    db_driver: string;
}

interface Props {
    backups: Backup[];
    retention: number;
    scheduled: boolean;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function Backups({ backups, retention, scheduled }: Props) {
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmation, setConfirmation] = useState('');

    const createBackup = () => {
        setCreating(true);
        router.post(
            route('admin.settings.backups.create'),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Backup dibuat'),
                onFinish: () => setCreating(false),
            },
        );
    };

    const deleteBackup = (backup: Backup) => {
        if (!confirm(`Hapus ${backup.filename}? Tindakan ini tidak bisa dibatalkan.`)) {
            return;
        }

        setBusy(backup.filename);
        router.delete(route('admin.settings.backups.destroy', backup.filename), {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const restoreBackup = (backup: Backup) => {
        setBusy(backup.filename);
        router.post(
            route('admin.settings.backups.restore', backup.filename),
            { confirmation },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setConfirmation('');
                    toast.success('Backup dipulihkan');
                },
                onFinish: () => setBusy(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backups" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <HeadingSmall
                            title="Backups"
                            description="Full-site archives with application files, themes, plugins, uploads and the database."
                        />
                        <Button onClick={createBackup} disabled={creating}>
                            {creating ? <LoaderCircle className="mr-2 h-4 w-4 animate-spin" /> : <HardDrive className="mr-2 h-4 w-4" />}
                            Backup now
                        </Button>
                    </div>

                    <Alert>
                        <Database className="h-4 w-4" />
                        <AlertTitle>What is inside</AlertTitle>
                        <AlertDescription>
                            Application code, themes, plugins, <code>storage/app/public</code> uploads and a database dump. The <code>.env</code>{' '}
                            file, <code>vendor</code>, <code>node_modules</code>, logs and caches are excluded. The newest {retention} archives are
                            kept{scheduled ? '; a backup also runs daily at 02:00 when the scheduler is configured' : ''}.
                        </AlertDescription>
                    </Alert>

                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Restoring overwrites the live site</AlertTitle>
                        <AlertDescription>
                            A safety backup is created first, but restoring replaces files and database data. Use it when you need to roll back
                            content or code — not as a normal deployment step.
                        </AlertDescription>
                    </Alert>

                    {backups.length === 0 ? (
                        <Card>
                            <CardContent className="text-muted-foreground py-10 text-center">
                                No backups yet. Create one before making large changes.
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">File</th>
                                        <th className="px-4 py-3 font-medium">Created</th>
                                        <th className="px-4 py-3 font-medium">Size</th>
                                        <th className="px-4 py-3 font-medium">Version</th>
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {backups.map((backup) => (
                                        <tr key={backup.filename} className="border-t">
                                            <td className="px-4 py-3">
                                                <div className="font-mono text-xs">{backup.filename}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {backup.type}
                                                    {backup.db_driver ? ` · ${backup.db_driver}` : ''}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">{backup.created_at}</td>
                                            <td className="px-4 py-3 whitespace-nowrap">{formatBytes(backup.size)}</td>
                                            <td className="px-4 py-3 whitespace-nowrap">{backup.app_version || '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <Button variant="outline" size="sm" asChild>
                                                        <a href={route('admin.settings.backups.download', backup.filename)}>
                                                            <Download className="mr-1 h-4 w-4" />
                                                            Download
                                                        </a>
                                                    </Button>

                                                    <Dialog>
                                                        <DialogTrigger asChild>
                                                            <Button variant="outline" size="sm">
                                                                <RotateCcw className="mr-1 h-4 w-4" />
                                                                Restore
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent>
                                                            <DialogHeader>
                                                                <DialogTitle>Restore this backup?</DialogTitle>
                                                                <DialogDescription>
                                                                    Files and database data will be replaced with the contents of {backup.filename}. A
                                                                    safety backup is created first. Type RESTORE to continue.
                                                                </DialogDescription>
                                                            </DialogHeader>

                                                            <div className="grid gap-2">
                                                                <Label htmlFor={`confirm-${backup.filename}`}>Confirmation</Label>
                                                                <Input
                                                                    id={`confirm-${backup.filename}`}
                                                                    value={confirmation}
                                                                    onChange={(event) => setConfirmation(event.target.value)}
                                                                    placeholder="RESTORE"
                                                                    autoComplete="off"
                                                                />
                                                            </div>

                                                            <DialogFooter>
                                                                <Button
                                                                    variant="destructive"
                                                                    disabled={confirmation !== 'RESTORE' || busy === backup.filename}
                                                                    onClick={() => restoreBackup(backup)}
                                                                >
                                                                    {busy === backup.filename ? (
                                                                        <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
                                                                    ) : (
                                                                        <RotateCcw className="mr-2 h-4 w-4" />
                                                                    )}
                                                                    Restore backup
                                                                </Button>
                                                            </DialogFooter>
                                                        </DialogContent>
                                                    </Dialog>

                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => deleteBackup(backup)}
                                                        disabled={busy === backup.filename}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
