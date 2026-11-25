import FlashMessages from '@/components/flash-messages';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UploadSecurityExtensionCallout, type ExtensionUploadSecurity } from '@/components/upload-security-callout';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, FileText, Plus, Power, PowerOff, Puzzle, Settings, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
interface SettingsField {
    key: string;
    label?: string;
    type?: string;
    default?: unknown;
}

interface SettingsSchema {
    fields: SettingsField[];
}

interface Compatibility {
    ok: boolean;
    errors: string[];
    warnings: string[];
}

interface Plugin {
    id: number;
    name: string;
    slug: string;
    folder_name: string;
    description: string | null;
    version: string;
    author: string | null;
    author_url: string | null;
    is_active: boolean;
    settings: Record<string, unknown> | null;
    requires: Record<string, unknown> | null;
    compatibility: Compatibility;
    settings_schema: SettingsSchema | null;
}

interface Props {
    plugins: Plugin[];
    uploadSecurity: ExtensionUploadSecurity;
}

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Plugins',
    },
];

export default function Index({ plugins, uploadSecurity }: Props) {
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;

    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [selectedPlugin, setSelectedPlugin] = useState<Plugin | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [signatureFile, setSignatureFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const signatureInputRef = useRef<HTMLInputElement>(null);
    const [settingsPlugin, setSettingsPlugin] = useState<Plugin | null>(null);
    const [settingsForm, setSettingsForm] = useState<Record<string, unknown>>({});

    const handleScan = () => {
        router.post(route('admin.plugins.scan'));
    };

    const handleActivate = (plugin: Plugin) => {
        router.post(route('admin.plugins.activate', plugin.id));
    };

    const handleDeactivate = (plugin: Plugin) => {
        router.post(route('admin.plugins.deactivate', plugin.id));
    };

    const handleDelete = () => {
        if (selectedPlugin) {
            router.delete(route('admin.plugins.destroy', selectedPlugin.id));
            setIsDeleteDialogOpen(false);
            setSelectedPlugin(null);
        }
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files[0]) {
            setSelectedFile(e.target.files[0]);
        }
    };

    const handleSignatureChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files[0]) {
            setSignatureFile(e.target.files[0]);
        }
    };

    const handleUpload = () => {
        if (!selectedFile || !signatureFile) return;

        const formData = new FormData();
        formData.append('plugin', selectedFile);
        formData.append('signature', signatureFile);

        router.post(route('admin.plugins.upload'), formData, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setIsUploading(true),
            onFinish: () => setIsUploading(false),
            onSuccess: () => {
                setIsUploadDialogOpen(false);
                setSelectedFile(null);
                setSignatureFile(null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
                if (signatureInputRef.current) {
                    signatureInputRef.current.value = '';
                }
            },
        });
    };

    const openSettings = (plugin: Plugin) => {
        setSettingsPlugin(plugin);
        const initial: Record<string, unknown> = {};
        const fields = plugin.settings_schema?.fields ?? [];
        for (const f of fields) {
            if (!f.key) continue;
            const v = plugin.settings?.[f.key];
            if (v !== undefined && v !== null) {
                initial[f.key] = v;
            } else if (f.default !== undefined) {
                initial[f.key] = f.default;
            } else {
                initial[f.key] = f.type === 'boolean' || f.type === 'bool' ? false : '';
            }
        }
        setSettingsForm(initial);
    };

    const handleSaveSettings = () => {
        if (!settingsPlugin) return;
        router.patch(route('admin.plugins.settings.update', settingsPlugin.id), settingsForm as Record<string, string | number | boolean>, {
            preserveScroll: true,
            onSuccess: () => setSettingsPlugin(null),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plugins" />

            <div className="p-6">
                <FlashMessages className="mb-6" />

                {/* Header Section */}
                <div className="mb-8">
                    <div className="mb-2 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Puzzle className="text-primary h-8 w-8" />
                            <h1 className="text-3xl font-bold tracking-tight">Plugins</h1>
                        </div>
                        <div className="flex gap-3">
                            <Dialog open={isUploadDialogOpen} onOpenChange={setIsUploadDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" className="shadow-sm">
                                        <Upload className="mr-2 h-4 w-4" />
                                        Upload Plugin
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-[425px]">
                                    <DialogHeader>
                                        <DialogTitle className="flex items-center gap-2">
                                            <Upload className="h-5 w-5" />
                                            Upload Plugin
                                        </DialogTitle>
                                        <DialogDescription className="text-muted-foreground pt-2 text-sm">
                                            ZIP maks. {uploadSecurity.zipMaxMb} MB dengan plugin.json dan index.php, plus signature{' '}
                                            <code className="text-xs">.sig.json</code> dari <code className="text-xs">nebula-pack</code>. Paket tanpa
                                            signature valid ditolak. Plugin menjalankan kode PHP — hanya sumber tepercaya.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="plugin" className="text-sm font-medium">
                                                Plugin File (ZIP)
                                            </Label>
                                            <Input
                                                id="plugin"
                                                type="file"
                                                accept=".zip"
                                                ref={fileInputRef}
                                                onChange={handleFileChange}
                                                className="cursor-pointer"
                                            />
                                            <InputError message={errors.plugin} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="plugin-signature" className="text-sm font-medium">
                                                Signature File (.sig.json)
                                            </Label>
                                            <Input
                                                id="plugin-signature"
                                                type="file"
                                                accept=".json,.sig.json,application/json,text/plain"
                                                ref={signatureInputRef}
                                                onChange={handleSignatureChange}
                                                className="cursor-pointer"
                                            />
                                            <InputError message={errors.signature} />
                                        </div>
                                    </div>
                                    <DialogFooter className="gap-2">
                                        <Button variant="outline" onClick={() => setIsUploadDialogOpen(false)}>
                                            Cancel
                                        </Button>
                                        <Button
                                            onClick={handleUpload}
                                            disabled={!selectedFile || !signatureFile || isUploading}
                                            className="min-w-[100px]"
                                        >
                                            {isUploading ? 'Uploading…' : selectedFile && signatureFile ? 'Upload' : 'Select Files'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                            <Button onClick={handleScan} variant="default" className="shadow-sm">
                                <Plus className="mr-2 h-4 w-4" />
                                Scan Plugins
                            </Button>
                        </div>
                    </div>
                    <p className="text-muted-foreground">Extend your site&apos;s functionality with plugins.</p>
                    <div className="mt-3 w-full">
                        <UploadSecurityExtensionCallout data={uploadSecurity} />
                    </div>
                </div>

                {/* Content Section */}
                <div className="bg-card rounded-xl border-0">
                    {plugins.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16">
                            <div className="bg-muted mb-4 rounded-full p-4">
                                <FileText className="text-muted-foreground h-8 w-8" />
                            </div>
                            <h3 className="mb-2 text-xl font-semibold">No plugins found</h3>
                            <p className="text-muted-foreground mb-6 max-w-md text-center">
                                Add a new plugin by uploading a ZIP file or placing it directly in the plugins directory
                            </p>
                            <div className="flex gap-3">
                                <Button onClick={handleScan} variant="outline" className="shadow-sm">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Scan Plugins
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-6 p-6 sm:grid-cols-2 lg:grid-cols-3">
                            {plugins.map((plugin) => (
                                <Card key={plugin.id} className="overflow-hidden">
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between gap-2">
                                            <span className="truncate">{plugin.name}</span>
                                            {plugin.is_active ? (
                                                <Badge variant="default" className="shrink-0 bg-green-500">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary" className="shrink-0">
                                                    Inactive
                                                </Badge>
                                            )}
                                        </CardTitle>
                                        <CardDescription>
                                            Version {plugin.version}
                                            {plugin.author && (
                                                <>
                                                    {' by '}
                                                    {plugin.author_url ? (
                                                        <a href={plugin.author_url} target="_blank" rel="noopener noreferrer" className="underline">
                                                            {plugin.author}
                                                        </a>
                                                    ) : (
                                                        plugin.author
                                                    )}
                                                </>
                                            )}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <p className="text-muted-foreground text-sm">{plugin.description || 'No description available'}</p>
                                        {!plugin.compatibility.ok && (
                                            <Alert variant="destructive">
                                                <AlertCircle className="h-4 w-4" />
                                                <AlertTitle>Requirements not met</AlertTitle>
                                                <AlertDescription>
                                                    <ul className="list-disc space-y-1 pl-4">
                                                        {plugin.compatibility.errors.map((e) => (
                                                            <li key={e}>{e}</li>
                                                        ))}
                                                    </ul>
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        {plugin.compatibility.ok && plugin.compatibility.warnings.length > 0 && (
                                            <Alert>
                                                <AlertTitle>Notice</AlertTitle>
                                                <AlertDescription>{plugin.compatibility.warnings.join(' ')}</AlertDescription>
                                            </Alert>
                                        )}
                                    </CardContent>
                                    <CardFooter className="flex flex-wrap gap-2">
                                        {plugin.settings_schema?.fields?.length ? (
                                            <Button variant="outline" size="sm" onClick={() => openSettings(plugin)}>
                                                <Settings className="mr-2 h-4 w-4" />
                                                Settings
                                            </Button>
                                        ) : null}
                                        {plugin.is_active ? (
                                            <Button variant="outline" onClick={() => handleDeactivate(plugin)} className="min-w-[120px] flex-1">
                                                <PowerOff className="mr-2 h-4 w-4" />
                                                Deactivate
                                            </Button>
                                        ) : (
                                            <>
                                                <Button
                                                    variant="default"
                                                    onClick={() => handleActivate(plugin)}
                                                    className="min-w-[120px] flex-1"
                                                    disabled={!plugin.compatibility.ok}
                                                >
                                                    <Power className="mr-2 h-4 w-4" />
                                                    Activate
                                                </Button>
                                                <Dialog
                                                    open={isDeleteDialogOpen && selectedPlugin?.id === plugin.id}
                                                    onOpenChange={(open) => {
                                                        setIsDeleteDialogOpen(open);
                                                        if (!open) setSelectedPlugin(null);
                                                    }}
                                                >
                                                    <DialogTrigger asChild>
                                                        <Button variant="destructive" size="icon" onClick={() => setSelectedPlugin(plugin)}>
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogHeader>
                                                            <DialogTitle>Delete Plugin</DialogTitle>
                                                            <DialogDescription>
                                                                Are you sure you want to delete &quot;{plugin.name}&quot;? This action cannot be
                                                                undone. The plugin files will be permanently removed from the server.
                                                            </DialogDescription>
                                                        </DialogHeader>
                                                        <DialogFooter>
                                                            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
                                                                Cancel
                                                            </Button>
                                                            <Button variant="destructive" onClick={handleDelete}>
                                                                Delete Plugin
                                                            </Button>
                                                        </DialogFooter>
                                                    </DialogContent>
                                                </Dialog>
                                            </>
                                        )}
                                    </CardFooter>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <Dialog open={settingsPlugin !== null} onOpenChange={(open) => !open && setSettingsPlugin(null)}>
                <DialogContent className="sm:max-w-md">
                    {settingsPlugin && settingsPlugin.settings_schema?.fields?.length ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>{settingsPlugin.name} settings</DialogTitle>
                                <DialogDescription>Values are stored as JSON on the plugin record.</DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-4 py-2">
                                {(settingsPlugin.settings_schema?.fields ?? []).map((field) => {
                                    const t = field.type ?? 'text';
                                    if (t === 'boolean' || t === 'bool') {
                                        return (
                                            <div key={field.key} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`pf-${settingsPlugin.id}-${field.key}`}
                                                    checked={Boolean(settingsForm[field.key])}
                                                    onCheckedChange={(c) => setSettingsForm((s) => ({ ...s, [field.key]: c === true }))}
                                                />
                                                <Label htmlFor={`pf-${settingsPlugin.id}-${field.key}`}>{field.label ?? field.key}</Label>
                                            </div>
                                        );
                                    }
                                    return (
                                        <div key={field.key} className="grid gap-2">
                                            <Label htmlFor={`pf-${settingsPlugin.id}-${field.key}`}>{field.label ?? field.key}</Label>
                                            <Input
                                                id={`pf-${settingsPlugin.id}-${field.key}`}
                                                type={t === 'number' || t === 'integer' || t === 'int' ? 'number' : 'text'}
                                                value={String(settingsForm[field.key] ?? '')}
                                                onChange={(e) => {
                                                    const v = e.target.value;
                                                    setSettingsForm((s) => ({
                                                        ...s,
                                                        [field.key]:
                                                            t === 'number' || t === 'integer' || t === 'int' ? (v === '' ? '' : Number(v)) : v,
                                                    }));
                                                }}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setSettingsPlugin(null)}>
                                    Cancel
                                </Button>
                                <Button onClick={handleSaveSettings}>Save</Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
