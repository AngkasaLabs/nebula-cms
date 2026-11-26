import InputError from '@/components/input-error';
import { ThemeCard } from '@/components/theme-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UploadSecurityExtensionCallout, type ExtensionUploadSecurity } from '@/components/upload-security-callout';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { FileText, Palette, Plus, Upload } from 'lucide-react';
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

interface Theme {
    id: number;
    name: string;
    description: string | null;
    version: string;
    author: string | null;
    is_active: boolean;
    preview: string | null;
    preview_url: string | null;
    has_preview: boolean;
    settings: Record<string, unknown> | null;
    settings_schema: SettingsSchema | null;
}

interface Props {
    themes: Theme[];
    uploadSecurity: ExtensionUploadSecurity;
}

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Themes',
    },
];

export default function Index({ themes, uploadSecurity }: Props) {
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Record<string, string>;

    const [isUploadDialogOpen, setIsUploadDialogOpen] = useState(false);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [signatureFile, setSignatureFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [settingsTheme, setSettingsTheme] = useState<Theme | null>(null);
    const [settingsForm, setSettingsForm] = useState<Record<string, unknown>>({});
    const fileInputRef = useRef<HTMLInputElement>(null);
    const signatureInputRef = useRef<HTMLInputElement>(null);
    const signatureEmptyInputRef = useRef<HTMLInputElement>(null);

    const handleScan = () => {
        router.post(route('admin.themes.scan'));
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

    const openSettings = (theme: Theme) => {
        setSettingsTheme(theme);

        const initial: Record<string, unknown> = {};
        for (const field of theme.settings_schema?.fields ?? []) {
            if (!field.key) continue;

            const value = theme.settings?.[field.key];
            if (value !== undefined && value !== null) {
                initial[field.key] = value;
            } else if (field.default !== undefined) {
                initial[field.key] = field.default;
            } else {
                initial[field.key] = field.type === 'boolean' || field.type === 'bool' ? false : '';
            }
        }

        setSettingsForm(initial);
    };

    const handleSaveSettings = () => {
        if (!settingsTheme) return;

        router.patch(route('admin.themes.settings.update', settingsTheme.id), settingsForm as Record<string, string | number | boolean>, {
            preserveScroll: true,
            onSuccess: () => setSettingsTheme(null),
        });
    };

    const handleUpload = () => {
        if (!selectedFile || !signatureFile) return;

        const formData = new FormData();
        formData.append('theme', selectedFile);
        formData.append('signature', signatureFile);

        router.post(route('admin.themes.upload'), formData, {
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
                if (signatureEmptyInputRef.current) {
                    signatureEmptyInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Themes" />

            <div className="p-6">
                {/* Header Section */}
                <div className="mb-8">
                    <div className="mb-2 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Palette className="text-primary h-8 w-8" />
                            <h1 className="text-3xl font-bold tracking-tight">Themes</h1>
                        </div>
                        <div className="flex gap-3">
                            <Dialog open={isUploadDialogOpen} onOpenChange={setIsUploadDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline" className="shadow-sm">
                                        <Upload className="mr-2 h-4 w-4" />
                                        Upload Theme
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-[425px]">
                                    <DialogHeader>
                                        <DialogTitle className="flex items-center gap-2">
                                            <Upload className="h-5 w-5" />
                                            Upload Theme
                                        </DialogTitle>
                                        <DialogDescription className="text-muted-foreground pt-2 text-sm">
                                            ZIP maks. {uploadSecurity.zipMaxMb} MB, berisi <code className="text-xs">theme.json</code> di root atau
                                            satu subfolder, plus signature <code className="text-xs">.sig.json</code> dari{' '}
                                            <code className="text-xs">nebula-pack</code>. Paket tanpa signature valid ditolak.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="theme" className="text-sm font-medium">
                                                Theme File (ZIP)
                                            </Label>
                                            <Input
                                                id="theme"
                                                type="file"
                                                accept=".zip"
                                                ref={fileInputRef}
                                                onChange={handleFileChange}
                                                className="cursor-pointer"
                                            />
                                            <InputError message={errors.theme} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="theme-signature" className="text-sm font-medium">
                                                Signature File (.sig.json)
                                            </Label>
                                            <Input
                                                id="theme-signature"
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
                                            Batal
                                        </Button>
                                        <Button
                                            onClick={handleUpload}
                                            disabled={!selectedFile || !signatureFile || isUploading}
                                            className="min-w-[100px]"
                                        >
                                            {isUploading ? 'Uploading…' : selectedFile && signatureFile ? 'Upload' : 'Pilih File'}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                            <Button onClick={handleScan} variant="default" className="shadow-sm">
                                <Plus className="mr-2 h-4 w-4" />
                                Scan Themes
                            </Button>
                        </div>
                    </div>
                    <p className="text-muted-foreground">Manage and customize your site's appearance with attractive themes.</p>
                    <div className="mt-3 w-full">
                        <UploadSecurityExtensionCallout data={uploadSecurity} />
                    </div>
                </div>

                {/* Content Section */}
                <div className="bg-card rounded-xl border-0">
                    {themes.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-6 py-16">
                            <div className="bg-muted mb-4 rounded-full p-4">
                                <FileText className="text-muted-foreground h-8 w-8" />
                            </div>
                            <h3 className="mb-2 text-xl font-semibold">No themes found</h3>
                            <p className="text-muted-foreground mb-6 max-w-md text-center">
                                Add a new theme by uploading a ZIP file or placing it directly in the theme directory
                            </p>
                            <div className="flex gap-3">
                                <Dialog open={isUploadDialogOpen} onOpenChange={setIsUploadDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="shadow-sm">
                                            <Upload className="mr-2 h-4 w-4" />
                                            Upload Theme
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle className="flex items-center gap-2">
                                                <Upload className="h-5 w-5" />
                                                Upload Theme
                                            </DialogTitle>
                                            <DialogDescription className="text-muted-foreground pt-2 text-sm">
                                                ZIP maks. {uploadSecurity.zipMaxMb} MB, berisi theme.json, plus signature .sig.json dari nebula-pack.
                                                Paket tanpa signature valid ditolak.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-4 py-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="theme-empty" className="text-sm font-medium">
                                                    Theme File (ZIP)
                                                </Label>
                                                <Input
                                                    id="theme-empty"
                                                    type="file"
                                                    accept=".zip"
                                                    ref={fileInputRef}
                                                    onChange={handleFileChange}
                                                    className="cursor-pointer"
                                                />
                                                <InputError message={errors.theme} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="theme-empty-signature" className="text-sm font-medium">
                                                    Signature File (.sig.json)
                                                </Label>
                                                <Input
                                                    id="theme-empty-signature"
                                                    type="file"
                                                    accept=".json,.sig.json,application/json,text/plain"
                                                    ref={signatureEmptyInputRef}
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
                                <Button onClick={handleScan} variant="outline" className="shadow-sm">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Scan Themes
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-6 p-6 sm:grid-cols-2 lg:grid-cols-3">
                            {themes.map((theme) => (
                                <ThemeCard
                                    key={theme.id}
                                    theme={theme}
                                    onSettings={theme.settings_schema?.fields?.length ? () => openSettings(theme) : undefined}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
            <Dialog open={settingsTheme !== null} onOpenChange={(open) => !open && setSettingsTheme(null)}>
                <DialogContent className="sm:max-w-md">
                    {settingsTheme && settingsTheme.settings_schema?.fields?.length ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>{settingsTheme.name} settings</DialogTitle>
                                <DialogDescription>Values are stored as JSON on the theme record.</DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-4 py-2">
                                {(settingsTheme.settings_schema?.fields ?? []).map((field) => {
                                    const type = field.type ?? 'text';

                                    if (type === 'boolean' || type === 'bool') {
                                        return (
                                            <div key={field.key} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`tf-${settingsTheme.id}-${field.key}`}
                                                    checked={Boolean(settingsForm[field.key])}
                                                    onCheckedChange={(checked) =>
                                                        setSettingsForm((current) => ({ ...current, [field.key]: checked === true }))
                                                    }
                                                />
                                                <Label htmlFor={`tf-${settingsTheme.id}-${field.key}`}>{field.label ?? field.key}</Label>
                                            </div>
                                        );
                                    }

                                    return (
                                        <div key={field.key} className="grid gap-2">
                                            <Label htmlFor={`tf-${settingsTheme.id}-${field.key}`}>{field.label ?? field.key}</Label>
                                            <Input
                                                id={`tf-${settingsTheme.id}-${field.key}`}
                                                type={type === 'number' || type === 'integer' || type === 'int' ? 'number' : 'text'}
                                                value={String(settingsForm[field.key] ?? '')}
                                                onChange={(event) => {
                                                    const value = event.target.value;
                                                    setSettingsForm((current) => ({
                                                        ...current,
                                                        [field.key]:
                                                            type === 'number' || type === 'integer' || type === 'int'
                                                                ? value === ''
                                                                    ? ''
                                                                    : Number(value)
                                                                : value,
                                                    }));
                                                }}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setSettingsTheme(null)}>
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
