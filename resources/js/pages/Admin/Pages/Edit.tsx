import { Editor } from '@/components/editor';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Page, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Calendar, Eye, History, Link2 } from 'lucide-react';
import { useCallback } from 'react';
import { toast } from 'sonner';

interface Props {
    page: Page;
    preview: {
        url: string;
        signed_url: string;
    };
}

interface FormData extends Record<string, string | number | null> {
    title: string;
    content: string;
    meta_description: string;
    meta_keywords: string;
    status: 'draft' | 'published' | 'scheduled' | 'pending_review';
    published_at: string;
    order: number;
}

function toLocalInput(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (part: number) => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Pages',
        href: route('admin.pages.index'),
    },
    {
        title: 'Edit',
    },
];

export default function Edit({ page, preview }: Props) {
    const { translations } = usePage<SharedData>().props;
    const tp = translations.posts;
    const { data, setData, put, processing, errors } = useForm<FormData>({
        title: page.title,
        content: page.content,
        meta_description: page.meta_description || '',
        meta_keywords: page.meta_keywords || '',
        status: page.status,
        published_at: page.status === 'scheduled' ? toLocalInput(page.published_at) : '',
        order: page.order,
    });

    const handleSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            put(route('admin.pages.update', page.id), {
                onSuccess: () => {
                    toast.success('Halaman berhasil diperbarui');
                },
            });
        },
        [put, page.id],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Page" />

            <div className="p-4">
                <form onSubmit={handleSubmit}>
                    <div className="mb-6 flex items-center justify-between">
                        <h1 className="text-2xl font-bold">Edit Page</h1>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="gap-1"
                                onClick={() => window.open(preview.url, '_blank', 'noopener,noreferrer')}
                            >
                                <Eye className="h-4 w-4" />
                                {tp.preview ?? 'Preview'}
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                className="gap-1"
                                onClick={async () => {
                                    try {
                                        await navigator.clipboard.writeText(preview.signed_url);
                                        toast.success(tp.preview_link_copied ?? 'Preview link copied');
                                    } catch {
                                        toast.error('Could not copy link');
                                    }
                                }}
                            >
                                <Link2 className="h-4 w-4" />
                                {tp.copy_preview_link ?? 'Copy preview link'}
                            </Button>
                            <Link href={route('admin.pages.revisions', page.id)}>
                                <Button type="button" variant="outline" size="sm" className="gap-1">
                                    <History className="h-4 w-4" />
                                    Revisions
                                </Button>
                            </Link>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="space-y-4 lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Content</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input
                                            id="title"
                                            value={data.title}
                                            onChange={(e) => setData('title', e.target.value)}
                                            placeholder="Enter page title"
                                        />
                                        {errors.title && <span className="text-sm text-red-500">{errors.title}</span>}
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Content</Label>
                                        <Editor value={data.content} onChange={(value) => setData('content', value)} />
                                        {errors.content && <span className="text-sm text-red-500">{errors.content}</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Settings</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="status">Status</Label>
                                        <Select
                                            value={data.status}
                                            onValueChange={(value: string) => {
                                                const status = value as FormData['status'];
                                                setData({
                                                    ...data,
                                                    status,
                                                    published_at: status === 'scheduled' ? data.published_at : '',
                                                });
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Pilih status" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="draft">Draft</SelectItem>
                                                <SelectItem value="pending_review">{tp.status_pending_review ?? 'Pending review'}</SelectItem>
                                                <SelectItem value="published">Published</SelectItem>
                                                <SelectItem value="scheduled">Scheduled</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.status && <span className="text-sm text-red-500">{errors.status}</span>}
                                    </div>

                                    {data.status === 'scheduled' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="published_at" className="flex items-center gap-2">
                                                <Calendar className="h-4 w-4" />
                                                Schedule Date & Time
                                            </Label>
                                            <Input
                                                id="published_at"
                                                type="datetime-local"
                                                value={data.published_at}
                                                onChange={(e) => setData('published_at', e.target.value)}
                                                min={new Date().toISOString().slice(0, 16)}
                                                required
                                            />
                                            {errors.published_at && <span className="text-sm text-red-500">{errors.published_at}</span>}
                                            <p className="text-muted-foreground text-xs">
                                                The page will be published automatically at the scheduled time.
                                            </p>
                                        </div>
                                    )}

                                    <div className="space-y-2">
                                        <Label htmlFor="order">Order</Label>
                                        <Input
                                            id="order"
                                            type="number"
                                            value={data.order}
                                            onChange={(e) => setData('order', Number(e.target.value))}
                                        />
                                        {errors.order && <span className="text-sm text-red-500">{errors.order}</span>}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>SEO</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="meta_description">Meta Description</Label>
                                        <Textarea
                                            id="meta_description"
                                            value={data.meta_description}
                                            onChange={(e) => setData('meta_description', e.target.value)}
                                            placeholder="Enter meta description for SEO"
                                            rows={3}
                                        />
                                        {errors.meta_description && <span className="text-sm text-red-500">{errors.meta_description}</span>}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="meta_keywords">Meta Keywords</Label>
                                        <Input
                                            id="meta_keywords"
                                            value={data.meta_keywords}
                                            onChange={(e) => setData('meta_keywords', e.target.value)}
                                            placeholder="Enter meta keywords for SEO"
                                        />
                                        {errors.meta_keywords && <span className="text-sm text-red-500">{errors.meta_keywords}</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
