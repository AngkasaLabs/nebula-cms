import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { sanitizeUrl } from '@/utils/urlValidator';
import axios from 'axios';
import { ImageIcon, UploadIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Media {
    id: number;
    name: string;
    url?: string;
    variant_urls?: Record<string, string>;
    mime_type?: string;
    size?: string;
    created_at?: string;
}

interface MediaPickerProps {
    media?: Media[];
    selectedMediaId?: string;
    onSelect: (mediaId: string) => void;
    onUpload: (file: File) => void;
    featuredImageUrl?: string;
}

type PickerFolder = {
    id: number;
    name: string;
    parent_id: number | null;
    is_folder: true;
};

type PickerMedia = {
    id: number;
    name: string;
    url: string;
    variant_urls: Record<string, string>;
    mime_type: string;
    size: string;
    created_at: string;
    is_folder: false;
};

type PickerPaginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
    per_page: number;
    total: number;
};

type PickerResponse = {
    folders: PickerFolder[];
    media: PickerPaginator<PickerMedia>;
    currentFolder: { id: number; name: string; parent_id: number | null } | null;
    breadcrumbs: { id: number; name: string }[];
    filters: { search?: string; type?: string; folder_id?: string };
    uploadSecurity: unknown;
};

export default function MediaPicker({ media = [], selectedMediaId, onSelect, onUpload, featuredImageUrl }: MediaPickerProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [activeTab, setActiveTab] = useState('upload');
    const [previewUrl, setPreviewUrl] = useState<string | undefined>(sanitizeUrl(featuredImageUrl || ''));

    const [isLoadingLibrary, setIsLoadingLibrary] = useState(false);
    const [libraryError, setLibraryError] = useState<string | null>(null);
    const [libraryFolderId, setLibraryFolderId] = useState<number | null>(null);
    const [librarySearch, setLibrarySearch] = useState('');
    const [libraryPage, setLibraryPage] = useState(1);
    const [libraryData, setLibraryData] = useState<PickerResponse | null>(null);

    useEffect(() => {
        // Cleanup old preview URL when component unmounts or when new file is selected
        return () => {
            if (previewUrl && previewUrl !== featuredImageUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl, featuredImageUrl]);

    useEffect(() => {
        setPreviewUrl(sanitizeUrl(featuredImageUrl || ''));
    }, [featuredImageUrl]);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            // Cleanup old preview if exists
            if (previewUrl && previewUrl !== featuredImageUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            // Create new preview
            const newPreviewUrl = URL.createObjectURL(file);
            setPreviewUrl(newPreviewUrl);
            onUpload(file);
            setIsOpen(false);
        }
    };

    const handleMediaSelect = (mediaId: string, url?: string) => {
        // Cleanup file preview if exists
        if (previewUrl && previewUrl !== featuredImageUrl) {
            URL.revokeObjectURL(previewUrl);
        }
        setPreviewUrl(sanitizeUrl(url || ''));
        onSelect(mediaId);
        setIsOpen(false);
    };

    const fetchLibrary = async (params?: { folderId?: number | null; search?: string; page?: number }) => {
        setIsLoadingLibrary(true);
        setLibraryError(null);
        try {
            const folderId = params?.folderId ?? libraryFolderId;
            const search = params?.search ?? librarySearch;
            const page = params?.page ?? libraryPage;
            const response = await axios.get<PickerResponse>(route('admin.media.picker'), {
                params: {
                    folder_id: folderId ?? undefined,
                    type: 'image',
                    search: search || undefined,
                    page,
                    per_page: 24,
                },
            });
            setLibraryData(response.data);
        } catch (e) {
            const message = e instanceof Error ? e.message : 'Gagal memuat media library';
            setLibraryError(message);
        } finally {
            setIsLoadingLibrary(false);
        }
    };

    useEffect(() => {
        if (!isOpen || activeTab !== 'media') {
            return;
        }
        fetchLibrary();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, activeTab]);

    const mediaItems = useMemo(() => {
        if (libraryData) {
            return libraryData.media.data;
        }
        return media.map((m) => ({
            id: m.id,
            name: m.name,
            url: m.url ?? '',
            variant_urls: m.variant_urls ?? {},
            mime_type: m.mime_type ?? '',
            size: m.size ?? '',
            created_at: m.created_at ?? '',
            is_folder: false as const,
        }));
    }, [libraryData, media]);

    const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        const file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            // Cleanup old preview if exists
            if (previewUrl && previewUrl !== featuredImageUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            // Create new preview
            const newPreviewUrl = URL.createObjectURL(file);
            setPreviewUrl(newPreviewUrl);
            onUpload(file);
            setIsOpen(false);
        }
    };

    const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-4">
                {previewUrl ? (
                    <div className="group relative">
                        <img
                            src={sanitizeUrl(previewUrl)}
                            alt="Featured"
                            className="h-[150px] w-[200px] rounded-lg border border-gray-700 object-cover"
                        />
                        <div className="bg-opacity-50 absolute inset-0 flex items-center justify-center rounded-lg bg-black opacity-0 transition-opacity group-hover:opacity-100">
                            <Button
                                type="button"
                                variant="outline"
                                className="border-white text-white hover:bg-white hover:text-black"
                                onClick={() => setIsOpen(true)}
                            >
                                Ganti Gambar
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="flex h-[150px] w-[200px] flex-col items-center justify-center gap-2 border-2 border-dashed hover:border-white"
                        onClick={() => setIsOpen(true)}
                    >
                        <ImageIcon className="h-8 w-8" />
                        <span>Select Featured Image</span>
                    </Button>
                )}
            </div>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Select Media</DialogTitle>
                    </DialogHeader>

                    <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
                        <TabsList className="w-full">
                            <TabsTrigger value="upload" className="w-full">
                                <UploadIcon className="mr-2 h-4 w-4" />
                                Upload
                            </TabsTrigger>
                            <TabsTrigger value="media" className="w-full">
                                <ImageIcon className="mr-2 h-4 w-4" />
                                Media Library
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="upload" className="mt-4">
                            <div className="space-y-4">
                                <div
                                    className="cursor-pointer rounded-lg border-2 border-dashed p-8 text-center"
                                    onDrop={handleDrop}
                                    onDragOver={handleDragOver}
                                >
                                    <Label htmlFor="file-upload" className="cursor-pointer">
                                        <UploadIcon className="mx-auto h-12 w-12 text-gray-400" />
                                        <span className="mt-2 block text-sm font-medium">Click to upload or drag & drop</span>
                                        <span className="mt-1 block text-xs text-gray-400">PNG, JPG, GIF up to 10MB</span>
                                    </Label>
                                    <Input id="file-upload" type="file" className="hidden" onChange={handleFileChange} accept="image/*" />
                                </div>
                            </div>
                        </TabsContent>

                        <TabsContent value="media" className="mt-4">
                            <div className="space-y-3">
                                <div className="flex gap-2">
                                    <Input value={librarySearch} onChange={(e) => setLibrarySearch(e.target.value)} placeholder="Cari media..." />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setLibraryPage(1);
                                            fetchLibrary({ search: librarySearch, page: 1 });
                                        }}
                                        disabled={isLoadingLibrary}
                                    >
                                        Cari
                                    </Button>
                                </div>

                                {libraryData?.breadcrumbs && libraryData.breadcrumbs.length > 0 && (
                                    <div className="text-muted-foreground flex flex-wrap gap-2 text-sm">
                                        <button
                                            type="button"
                                            className="underline"
                                            onClick={() => {
                                                setLibraryFolderId(null);
                                                setLibraryPage(1);
                                                fetchLibrary({ folderId: null, page: 1 });
                                            }}
                                        >
                                            Root
                                        </button>
                                        {libraryData.breadcrumbs.map((bc) => (
                                            <button
                                                key={bc.id}
                                                type="button"
                                                className="underline"
                                                onClick={() => {
                                                    setLibraryFolderId(bc.id);
                                                    setLibraryPage(1);
                                                    fetchLibrary({ folderId: bc.id, page: 1 });
                                                }}
                                            >
                                                / {bc.name}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {libraryError && <div className="text-destructive text-sm">{libraryError}</div>}

                                <div className="grid grid-cols-3 gap-4">
                                    {libraryData?.folders?.map((folder) => (
                                        <button
                                            key={`folder-${folder.id}`}
                                            type="button"
                                            className="rounded-lg border p-3 text-left hover:bg-gray-900"
                                            onClick={() => {
                                                setLibraryFolderId(folder.id);
                                                setLibraryPage(1);
                                                fetchLibrary({ folderId: folder.id, page: 1 });
                                            }}
                                        >
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-10 w-10 items-center justify-center rounded bg-gray-800">
                                                    <ImageIcon className="h-5 w-5 text-gray-400" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="truncate font-medium">{folder.name}</div>
                                                    <div className="text-muted-foreground text-xs">Folder</div>
                                                </div>
                                            </div>
                                        </button>
                                    ))}

                                    {mediaItems.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            className={`group relative cursor-pointer overflow-hidden rounded-lg ${
                                                selectedMediaId === item.id.toString() ? 'ring-2 ring-white' : ''
                                            }`}
                                            onClick={() => handleMediaSelect(item.id.toString(), item.url)}
                                        >
                                            {item.url ? (
                                                <img src={sanitizeUrl(item.url)} alt={item.name} className="h-32 w-full object-cover" />
                                            ) : (
                                                <div className="flex h-32 w-full items-center justify-center bg-gray-800">
                                                    <ImageIcon className="h-8 w-8 text-gray-400" />
                                                </div>
                                            )}
                                            <div className="bg-opacity-50 absolute inset-0 flex items-center justify-center bg-black opacity-0 transition-opacity group-hover:opacity-100">
                                                <span className="line-clamp-2 px-2 text-center text-sm text-white">{item.name}</span>
                                            </div>
                                        </button>
                                    ))}
                                </div>

                                {libraryData && (
                                    <div className="flex items-center justify-between pt-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={isLoadingLibrary || !libraryData.media.prev_page_url}
                                            onClick={() => {
                                                const nextPage = Math.max(1, libraryData.media.current_page - 1);
                                                setLibraryPage(nextPage);
                                                fetchLibrary({ page: nextPage });
                                            }}
                                        >
                                            Prev
                                        </Button>
                                        <div className="text-muted-foreground text-sm">
                                            Page {libraryData.media.current_page} / {libraryData.media.last_page}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={isLoadingLibrary || !libraryData.media.next_page_url}
                                            onClick={() => {
                                                const nextPage = libraryData.media.current_page + 1;
                                                setLibraryPage(nextPage);
                                                fetchLibrary({ page: nextPage });
                                            }}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </TabsContent>
                    </Tabs>
                </DialogContent>
            </Dialog>
        </div>
    );
}
