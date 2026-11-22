import { Head, router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Download, Package, Puzzle, RefreshCw, Search, ShieldCheck, Store } from 'lucide-react';
import { FormEvent, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

interface StorePackage {
    slug: string;
    type: 'plugin' | 'theme';
    name: string;
    description: string | null;
    category: string | null;
    author: string | null;
    icon: string | null;
    screenshot: string | null;
    pricing: 'free' | 'paid';
    latest_version: string | null;
    requires: Record<string, string>;
    downloads: number;
    updated_at: string | null;
    installed_version: string | null;
    update_available: boolean;
    compatible: boolean;
    status: 'installed' | 'update_available' | 'incompatible' | 'not_installed';
    latest_changelog: string | null;
}

interface Props {
    packages: StorePackage[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    filters: {
        type: string | null;
        q: string | null;
    };
    storeUrl: string;
    storeEnabled: boolean;
    error: string | null;
    updatedCount: number;
    checkedAt: string | null;
    cmsVersion: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/admin/dashboard' },
    { title: 'Marketplace', href: '/admin/marketplace' },
];

export default function StoreIndex({
    packages,
    meta,
    filters,
    storeUrl,
    error,
    updatedCount,
    checkedAt,
    cmsVersion,
}: Props) {
    const [installing, setInstalling] = useState<string | null>(null);

    const install = (pkg: StorePackage) => {
        const key = `${pkg.type}:${pkg.slug}`;
        const isUpdate = pkg.status === 'update_available';

        if (isUpdate && !window.confirm(`Update ${pkg.name} to v${pkg.latest_version}? Existing files will be replaced.`)) {
            return;
        }

        setInstalling(key);

        router.post(
            '/admin/marketplace/install',
            { slug: pkg.slug, type: pkg.type, version: pkg.latest_version },
            {
                preserveScroll: true,
                onFinish: () => setInstalling(null),
            },
        );
    };

    const refresh = (event: FormEvent) => {
        event.preventDefault();
        router.post('/admin/marketplace/refresh', {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Marketplace" />

            <div className="space-y-6 p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="bg-primary/10 text-primary rounded-xl p-3">
                            <Store className="h-6 w-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">Marketplace</h1>
                            <p className="text-muted-foreground text-sm">
                                Install signed plugins and themes from{' '}
                                <span className="font-medium">{storeUrl || 'the store'}</span>.
                            </p>
                        </div>
                    </div>

                    <form onSubmit={refresh} className="flex items-center gap-2">
                        <Button type="submit" variant="outline">
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Refresh
                        </Button>
                    </form>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Store unavailable</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <Alert>
                    <ShieldCheck className="h-4 w-4" />
                    <AlertTitle>Signed packages with automatic rollback</AlertTitle>
                    <AlertDescription>
                        Every download is verified before installation. Updates snapshot the installed package
                        first; if an update fails, the previous files and metadata are restored automatically.
                    </AlertDescription>
                </Alert>

                <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <span>
                        {meta.total} package(s) · NebulaCMS {cmsVersion}
                    </span>
                    {updatedCount > 0 && <Badge>{updatedCount} update(s) available</Badge>}
                    {checkedAt && <span>Updates checked {new Date(checkedAt).toLocaleString()}</span>}
                </div>

                <form
                    method="get"
                    action="/admin/marketplace"
                    className="flex flex-wrap items-center gap-2"
                >
                    <Input
                        type="search"
                        name="q"
                        defaultValue={filters.q ?? ''}
                        placeholder="Search packages…"
                        className="max-w-xs"
                    />
                    <select
                        name="type"
                        defaultValue={filters.type ?? ''}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">All types</option>
                        <option value="plugin">Plugins</option>
                        <option value="theme">Themes</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        <Search className="mr-2 h-4 w-4" />
                        Search
                    </Button>
                </form>

                {packages.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                            <Store className="text-muted-foreground h-10 w-10" />
                            <div>
                                <p className="font-medium">No packages found</p>
                                <p className="text-muted-foreground text-sm">
                                    Try a different search or check the store URL in Settings.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {packages.map((pkg) => {
                            const key = `${pkg.type}:${pkg.slug}`;
                            const busy = installing === key;

                            return (
                                <Card key={key} className="flex flex-col">
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex gap-3">
                                                <div className="bg-muted rounded-xl p-2.5">
                                                    {pkg.type === 'theme' ? (
                                                        <Package className="h-5 w-5" />
                                                    ) : (
                                                        <Puzzle className="h-5 w-5" />
                                                    )}
                                                </div>
                                                <div>
                                                    <CardTitle className="flex flex-wrap items-center gap-2">
                                                        {pkg.name}
                                                        <Badge variant="outline">{pkg.type}</Badge>
                                                        {pkg.pricing === 'paid' && <Badge variant="secondary">paid</Badge>}
                                                    </CardTitle>
                                                    <CardDescription>
                                                        {pkg.slug}
                                                        {pkg.author ? ` · ${pkg.author}` : ''}
                                                    </CardDescription>
                                                </div>
                                            </div>
                                        </div>
                                    </CardHeader>

                                    <CardContent className="flex flex-1 flex-col gap-4">
                                        {pkg.description && (
                                            <p className="text-muted-foreground text-sm whitespace-pre-line">
                                                {pkg.description}
                                            </p>
                                        )}

                                        {pkg.latest_changelog && pkg.status === 'update_available' && (
                                            <div className="bg-muted/50 rounded-lg border p-3">
                                                <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                                    What's new in v{pkg.latest_version}
                                                </p>
                                                <p className="text-muted-foreground text-sm whitespace-pre-line">
                                                    {pkg.latest_changelog}
                                                </p>
                                            </div>
                                        )}

                                        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                            {pkg.latest_version && <span>v{pkg.latest_version}</span>}
                                            <span>{pkg.downloads.toLocaleString()} downloads</span>
                                            {pkg.installed_version && <span>installed v{pkg.installed_version}</span>}
                                            {pkg.requires?.cms_version && (
                                                <span>requires NebulaCMS {pkg.requires.cms_version}</span>
                                            )}
                                        </div>

                                        <div className="mt-auto flex flex-wrap items-center justify-between gap-2">
                                            <StatusBadge pkg={pkg} />

                                            {pkg.status === 'installed' ? (
                                                <Button variant="outline" size="sm" disabled>
                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                    Installed
                                                </Button>
                                            ) : pkg.status === 'incompatible' ? (
                                                <Button variant="outline" size="sm" disabled>
                                                    Incompatible
                                                </Button>
                                            ) : (
                                                <Button size="sm" onClick={() => install(pkg)} disabled={busy}>
                                                    <Download className="mr-2 h-4 w-4" />
                                                    {busy
                                                        ? 'Installing…'
                                                        : pkg.status === 'update_available'
                                                          ? 'Update'
                                                          : 'Install'}
                                                </Button>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {meta.last_page > 1 && (
                    <div className="flex items-center justify-center gap-4 text-sm">
                        {meta.current_page > 1 && (
                            <a
                                className="text-primary underline-offset-2 hover:underline"
                                href={`/admin/marketplace?${new URLSearchParams({
                                    ...(filters.q ? { q: filters.q } : {}),
                                    ...(filters.type ? { type: filters.type } : {}),
                                    page: String(meta.current_page - 1),
                                }).toString()}`}
                            >
                                ← Previous
                            </a>
                        )}
                        <span className="text-muted-foreground">
                            Page {meta.current_page} of {meta.last_page}
                        </span>
                        {meta.current_page < meta.last_page && (
                            <a
                                className="text-primary underline-offset-2 hover:underline"
                                href={`/admin/marketplace?${new URLSearchParams({
                                    ...(filters.q ? { q: filters.q } : {}),
                                    ...(filters.type ? { type: filters.type } : {}),
                                    page: String(meta.current_page + 1),
                                }).toString()}`}
                            >
                                Next →
                            </a>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function StatusBadge({ pkg }: { pkg: StorePackage }) {
    if (pkg.status === 'update_available') {
        return <Badge>Update available</Badge>;
    }

    if (pkg.status === 'installed') {
        return <Badge variant="secondary">Installed</Badge>;
    }

    if (pkg.status === 'incompatible') {
        return <Badge variant="destructive">Incompatible</Badge>;
    }

    return <Badge variant="outline">Not installed</Badge>;
}
