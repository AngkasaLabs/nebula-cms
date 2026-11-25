import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UploadSecurityMediaCallout, type MediaUploadSecurity } from '@/components/upload-security-callout';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, File, FileText, Film, Folder, FolderPlus, Image as ImageIcon, Loader2, Music, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';

interface Media {
    id: number;
    name: string;
    url: string;
    mime_type: string;
    size: string;
    dimensions?: {
        width: number;
        height: number;
    };
    created_at: string;
    is_folder: boolean;
    items_count?: number;
    user: {
        name: string;
    } | null;
}

interface Folder {
    id: number;
    name: string;
    is_folder: true;
    items_count: number;
}

interface Breadcrumb {
    id: number;
    name: string;
}

interface Props {
    folders: Folder[];
    allFolders: { id: number; name: string; parent_id: number | null }[];
    media: {
        data: Media[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    currentFolder: Folder | null;
    breadcrumbs: Breadcrumb[];
    filters: {
        search?: string;
        type?: string;
        folder_id?: string;
    };
    uploadSecurity: MediaUploadSecurity;
}

export default function Index({ media, folders, allFolders, currentFolder, breadcrumbs, filters, uploadSecurity }: Props) {
    const [uploading, setUploading] = useState(false);
    const [search, setSearch] = useState(filters.search || '');
    const [isCreateFolderOpen, setIsCreateFolderOpen] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');

    // Multi-select state
    const [isSelectMode, setIsSelectMode] = useState(false);
    const [selectedMedia, setSelectedMedia] = useState<number[]>([]);
    const [selectedFolders, setSelectedFolders] = useState<number[]>([]);
    const [isMoveDialogOpen, setIsMoveDialogOpen] = useState(false);
    const [targetFolderId, setTargetFolderId] = useState<string>(''); // string because select value

    const onDrop = useCallback(
        async (acceptedFiles: File[]) => {
            setUploading(true);
            const formData = new FormData();
            acceptedFiles.forEach((file) => {
                formData.append('files[]', file);
            });

            if (currentFolder) {
                formData.append('folder_id', currentFolder.id.toString());
            }

            try {
                router.post(route('admin.media.store'), formData, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        setUploading(false);
                    },
                    onError: (errors) => {
                        setUploading(false);
                        console.error('Upload failed:', errors);
                    },
                    onFinish: () => {
                        setUploading(false);
                    },
                });
            } catch (error) {
                console.error('Upload failed:', error);
                setUploading(false);
            }
        },
        [currentFolder],
    );

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: {
            'image/*': [],
            'video/*': [],
            'audio/*': [],
            'application/pdf': [],
            'application/msword': [],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [],
        },
        noClick: true,
        disabled: isSelectMode, // Disable dropzone in select mode to avoid confusion
    });

    const getFileIcon = (mimeType: string | null) => {
        if (!mimeType) return <File className="h-10 w-10 text-gray-400" />;
        if (mimeType.startsWith('image/')) return <ImageIcon className="h-10 w-10 text-blue-500" />;
        if (mimeType.startsWith('video/')) return <Film className="h-10 w-10 text-red-500" />;
        if (mimeType.startsWith('audio/')) return <Music className="h-10 w-10 text-yellow-500" />;
        if (mimeType.startsWith('application/pdf')) return <FileText className="h-10 w-10 text-red-600" />;
        return <File className="h-10 w-10 text-gray-400" />;
    };

    const handleSearch = (value: string) => {
        setSearch(value);
        router.get(
            route('admin.media.index'),
            { search: value, type: filters.type, folder_id: currentFolder?.id },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const handleCreateFolder = () => {
        router.post(
            route('admin.media.folders.store'),
            {
                name: newFolderName,
                parent_id: currentFolder?.id,
            },
            {
                onSuccess: () => {
                    setIsCreateFolderOpen(false);
                    setNewFolderName('');
                },
            },
        );
    };

    // Selection Handlers
    const toggleSelectMode = () => {
        setIsSelectMode(!isSelectMode);
        setSelectedMedia([]);
        setSelectedFolders([]);
    };

    const toggleMediaSelection = (id: number) => {
        if (selectedMedia.includes(id)) {
            setSelectedMedia(selectedMedia.filter((i) => i !== id));
        } else {
            setSelectedMedia([...selectedMedia, id]);
        }
    };

    const toggleFolderSelection = (id: number) => {
        if (selectedFolders.includes(id)) {
            setSelectedFolders(selectedFolders.filter((i) => i !== id));
        } else {
            setSelectedFolders([...selectedFolders, id]);
        }
    };

    const handleBulkDelete = () => {
        if (confirm(`Are you sure you want to delete ${selectedMedia.length} files and ${selectedFolders.length} folders?`)) {
            router.post(
                route('admin.media.bulk-destroy'),
                {
                    ids: selectedMedia,
                    folder_ids: selectedFolders,
                },
                {
                    onSuccess: () => {
                        setSelectedMedia([]);
                        setSelectedFolders([]);
                        if (media.data.length === 0 && folders.length === 0) setIsSelectMode(false);
                    },
                },
            );
        }
    };

    const handleBulkMove = () => {
        const targetId = targetFolderId === 'root' ? null : parseInt(targetFolderId);

        router.post(
            route('admin.media.move'),
            {
                ids: selectedMedia,
                folder_ids: selectedFolders,
                target_folder_id: targetId,
            },
            {
                onSuccess: () => {
                    setIsMoveDialogOpen(false);
                    setSelectedMedia([]);
                    setSelectedFolders([]);
                    setIsSelectMode(false);
                },
            },
        );
    };

    // Combine static and dynamic breadcrumbs
    const pageBreadcrumbs = [
        {
            title: 'Dashboard',
            href: route('admin.dashboard'),
        },
        {
            title: 'Media',
            href: route('admin.media.index'),
        },
        ...breadcrumbs.map((b) => ({
            title: b.name,
            href: route('admin.media.index', { folder_id: b.id }),
        })),
    ];

    if (currentFolder) {
        pageBreadcrumbs.push({
            title: currentFolder.name,
            href: route('admin.media.index', { folder_id: currentFolder.id }),
        });
    }

    return (
        <AppLayout breadcrumbs={pageBreadcrumbs}>
            <Head title="Media" />

            <div className="flex h-full flex-col p-6" {...getRootProps()}>
                <input {...getInputProps()} />

                {/* Drag Overlay (same as before) */}
                {isDragActive && (
                    <div className="bg-primary/20 border-primary fixed inset-0 z-50 m-4 flex items-center justify-center rounded-xl border-4 border-dashed backdrop-blur-sm">
                        <div className="bg-background animate-in zoom-in flex flex-col items-center rounded-xl p-8 shadow-lg duration-300">
                            <Loader2 className="text-primary mb-4 h-12 w-12 animate-spin" />
                            <h3 className="text-primary text-2xl font-bold">Drop files to upload</h3>
                            <p className="text-muted-foreground mt-2">Files will be uploaded to {currentFolder ? currentFolder.name : 'root'}</p>
                        </div>
                    </div>
                )}

                <div className="mb-6 flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Media Library</h1>
                        <p className="text-muted-foreground">Manage files and folders</p>
                    </div>

                    {/* Toolbar */}
                    <div className="flex w-full items-center gap-3 md:w-auto">
                        {isSelectMode ? (
                            <div className="bg-accent/20 animate-in fade-in slide-in-from-right-4 flex items-center gap-2 rounded-lg border p-1">
                                <span className="px-2 text-sm font-medium">{selectedMedia.length + selectedFolders.length} selected</span>
                                <div className="bg-border mx-1 h-4 w-px" />
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setIsMoveDialogOpen(true)}
                                    disabled={selectedMedia.length === 0 && selectedFolders.length === 0}
                                >
                                    Move
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive hover:text-destructive"
                                    onClick={handleBulkDelete}
                                    disabled={selectedMedia.length === 0 && selectedFolders.length === 0}
                                >
                                    Delete
                                </Button>
                                <div className="bg-border mx-1 h-4 w-px" />
                                <Button size="sm" variant="outline" onClick={toggleSelectMode}>
                                    Cancel
                                </Button>
                            </div>
                        ) : (
                            <>
                                <div className="relative flex-1 md:w-64">
                                    <Input
                                        type="text"
                                        placeholder="Search files..."
                                        value={search}
                                        onChange={(e) => handleSearch(e.target.value)}
                                        className="w-full"
                                    />
                                </div>
                                <Button variant={isSelectMode ? 'secondary' : 'outline'} onClick={toggleSelectMode}>
                                    Select
                                </Button>
                                <Dialog open={isCreateFolderOpen} onOpenChange={setIsCreateFolderOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline">
                                            <FolderPlus className="mr-2 h-4 w-4" />
                                            New Folder
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Create New Folder</DialogTitle>
                                            <DialogDescription>Create a folder to organize your media files.</DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-4 py-4">
                                            <div className="grid gap-2">
                                                <Label htmlFor="name">Folder Name</Label>
                                                <Input
                                                    id="name"
                                                    value={newFolderName}
                                                    onChange={(e) => setNewFolderName(e.target.value)}
                                                    placeholder="e.g. Images"
                                                    onKeyDown={(e) => e.key === 'Enter' && handleCreateFolder()}
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" onClick={() => setIsCreateFolderOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button onClick={handleCreateFolder} disabled={!newFolderName}>
                                                Create Folder
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <div
                                    className="cursor-pointer"
                                    onClick={() => (document.querySelector('input[type="file"]') as HTMLInputElement)?.click()}
                                >
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Upload
                                    </Button>
                                </div>
                            </>
                        )}
                    </div>
                </div>

                <div className="mb-6 w-full">
                    <UploadSecurityMediaCallout data={uploadSecurity} />
                </div>

                {/* Move Dialog */}
                <Dialog open={isMoveDialogOpen} onOpenChange={setIsMoveDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Move Items</DialogTitle>
                            <DialogDescription>Select the destination folder for the selected items.</DialogDescription>
                        </DialogHeader>
                        <div className="py-4">
                            <Label htmlFor="targetFolder">Destination Folder</Label>
                            <select
                                id="targetFolder"
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring mt-2 flex h-10 w-full rounded-md border px-3 py-2 text-sm file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                value={targetFolderId}
                                onChange={(e) => setTargetFolderId(e.target.value)}
                            >
                                <option value="" disabled>
                                    Select a folder...
                                </option>
                                <option value="root">Root Folder</option>
                                {allFolders.map((f) => (
                                    <option key={f.id} value={f.id} disabled={selectedFolders.includes(f.id)}>
                                        {f.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setIsMoveDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button onClick={handleBulkMove} disabled={!targetFolderId}>
                                Move Items
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {uploading && (
                    <div className="bg-background animate-in slide-in-from-bottom fixed right-6 bottom-6 z-50 flex items-center gap-3 rounded-lg border p-4 shadow-lg">
                        <Loader2 className="text-primary h-5 w-5 animate-spin" />
                        <div>
                            <p className="font-medium">Uploading files...</p>
                            <p className="text-muted-foreground text-xs">Please wait while we process your uploads.</p>
                        </div>
                    </div>
                )}

                {/* Breadcrumbs */}
                <div className="text-muted-foreground mb-6 flex items-center overflow-x-auto pb-2 text-sm">
                    <Link
                        href={route('admin.media.index')}
                        className={`hover:text-foreground flex items-center transition-colors ${!currentFolder ? 'text-foreground font-semibold' : ''}`}
                    >
                        <Folder className="mr-1.5 h-4 w-4" />
                        Root
                    </Link>
                    {breadcrumbs.map((crumb) => (
                        <div key={crumb.id} className="flex items-center">
                            <ChevronRight className="mx-1 h-4 w-4" />
                            <Link href={route('admin.media.index', { folder_id: crumb.id })} className="hover:text-foreground transition-colors">
                                {crumb.name}
                            </Link>
                        </div>
                    ))}
                    {currentFolder && (
                        <div className="flex items-center">
                            <ChevronRight className="mx-1 h-4 w-4" />
                            <span className="text-foreground font-semibold">{currentFolder.name}</span>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                    {/* Render Folders */}
                    {folders.map((folder) => {
                        const isSelected = selectedFolders.includes(folder.id);
                        return (
                            <Card
                                key={`folder-${folder.id}`}
                                className={`group hover:bg-accent/50 relative flex aspect-square cursor-pointer flex-col border-2 transition-colors ${isSelected ? 'border-primary bg-accent/20' : 'hover:border-primary/50'}`}
                                onClick={() => {
                                    if (isSelectMode) {
                                        toggleFolderSelection(folder.id);
                                    } else {
                                        router.visit(route('admin.media.index', { folder_id: folder.id }));
                                    }
                                }}
                            >
                                {isSelectMode && (
                                    <div className="absolute top-2 left-2 z-10">
                                        <div
                                            className={`h-5 w-5 rounded border ${isSelected ? 'bg-primary border-primary' : 'bg-background border-input'} flex items-center justify-center`}
                                        >
                                            {isSelected && (
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="3"
                                                    className="text-primary-foreground h-3 w-3"
                                                >
                                                    <polyline points="20 6 9 17 4 12" />
                                                </svg>
                                            )}
                                        </div>
                                    </div>
                                )}
                                <div className="flex flex-1 flex-col items-center justify-center p-4">
                                    <Folder
                                        className={`h-16 w-16 fill-yellow-400/20 text-yellow-400 transition-transform duration-300 ${!isSelectMode && 'group-hover:scale-110'}`}
                                    />
                                </div>
                                <div className="bg-card/50 border-t p-3">
                                    <p className="truncate text-center text-sm font-medium">{folder.name}</p>
                                    <p className="text-muted-foreground text-center text-xs">{folder.items_count} items</p>
                                </div>
                            </Card>
                        );
                    })}

                    {/* Render Media */}
                    {media.data.map((item) => {
                        const isSelected = selectedMedia.includes(item.id);
                        return (
                            <Card
                                key={`media-${item.id}`}
                                className={`group relative flex aspect-square cursor-pointer flex-col overflow-hidden transition-shadow hover:shadow-md ${isSelected ? 'ring-primary ring-2' : ''}`}
                                onClick={() => {
                                    if (isSelectMode) {
                                        toggleMediaSelection(item.id);
                                    }
                                }}
                            >
                                {isSelectMode && (
                                    <div className="absolute top-2 left-2 z-10">
                                        <div
                                            className={`h-5 w-5 rounded border ${isSelected ? 'bg-primary border-primary' : 'bg-background border-input'} flex items-center justify-center`}
                                        >
                                            {isSelected && (
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="3"
                                                    className="text-primary-foreground h-3 w-3"
                                                >
                                                    <polyline points="20 6 9 17 4 12" />
                                                </svg>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div className="bg-muted/30 relative flex-1">
                                    {item.mime_type?.startsWith('image/') ? (
                                        <img
                                            src={item.url}
                                            alt={item.name}
                                            className={`h-full w-full object-cover transition-transform duration-500 ${!isSelectMode && 'group-hover:scale-105'}`}
                                            loading="lazy"
                                        />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center">{getFileIcon(item.mime_type)}</div>
                                    )}

                                    {!isSelectMode && (
                                        <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/40 p-2 opacity-0 backdrop-blur-[1px] transition-opacity group-hover:opacity-100">
                                            <Button
                                                variant="secondary"
                                                size="icon"
                                                className="h-8 w-8 rounded-full"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    router.visit(route('admin.media.show', item.id));
                                                }}
                                                title="View Details"
                                            >
                                                <ImageIcon className="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                size="icon"
                                                className="h-8 w-8 rounded-full"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    window.open(route('admin.media.download', item.id));
                                                }}
                                                title="Download"
                                            >
                                                <Plus className="h-4 w-4 rotate-180" />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                                <div className="bg-card border-t p-3 text-center">
                                    <p className="w-full truncate text-sm font-medium" title={item.name}>
                                        {item.name}
                                    </p>
                                    <p className="text-muted-foreground mt-0.5 text-[10px] tracking-wider uppercase">
                                        {item.mime_type?.split('/')[1] || 'FILE'} • {item.size}
                                    </p>
                                </div>
                            </Card>
                        );
                    })}

                    {folders.length === 0 && media.data.length === 0 && (
                        <div className="bg-accent/10 col-span-full flex flex-col items-center justify-center rounded-xl border-2 border-dashed py-12 text-center">
                            <div className="bg-background mb-4 rounded-full p-4 shadow-sm">
                                <FolderPlus className="text-muted-foreground h-8 w-8" />
                            </div>
                            <h3 className="text-lg font-semibold">Empty Folder</h3>
                            <p className="text-muted-foreground mt-1 mb-4 max-w-xs">
                                {search ? 'No results found.' : 'Drag files here or create a folder to get started.'}
                            </p>
                            {!search && (
                                <div className="flex gap-2">
                                    <Button variant="outline" onClick={() => setIsCreateFolderOpen(true)}>
                                        Create Folder
                                    </Button>
                                    <div onClick={() => (document.querySelector('input[type="file"]') as HTMLInputElement)?.click()}>
                                        <Button>Upload Files</Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {media.last_page > 1 && (
                    <div className="mt-8 flex justify-center pb-8">
                        <div className="flex space-x-2">
                            {Array.from({ length: media.last_page }, (_, i) => i + 1).map((page) => (
                                <Button
                                    key={page}
                                    variant={page === media.current_page ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() =>
                                        router.get(
                                            route('admin.media.index'),
                                            { page, search, type: filters.type, folder_id: currentFolder?.id },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    {page}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
