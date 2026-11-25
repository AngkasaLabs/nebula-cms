import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Props {
    tag?: {
        id: number;
        name: string;
        description: string;
        color: string;
    };
    errors?: {
        name?: string;
        description?: string;
        color?: string;
    };
}

export default function Form({ tag, errors }: Props) {
    const { data, setData, post, put, processing } = useForm({
        name: tag?.name || '',
        description: tag?.description || '',
        color: tag?.color || '#000000',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (tag) {
            put(route('admin.tags.update', tag.id));
        } else {
            post(route('admin.tags.store'));
        }
    };

    return (
        <div className="p-4">
            <form onSubmit={handleSubmit}>
                <Card>
                    <CardHeader>
                        <CardTitle>{tag ? 'Edit Tag' : 'Create Tag'}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div>
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors?.name && <p className="mt-1 text-sm text-red-500">{errors.name}</p>}
                        </div>
                        <div>
                            <Label htmlFor="description">Description</Label>
                            <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                            {errors?.description && <p className="mt-1 text-sm text-red-500">{errors.description}</p>}
                        </div>
                        <div>
                            <Label htmlFor="color">Color</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="color"
                                    type="color"
                                    value={data.color}
                                    onChange={(e) => setData('color', e.target.value)}
                                    className="h-10 w-20 p-1"
                                />
                                <Input
                                    type="text"
                                    value={data.color}
                                    onChange={(e) => setData('color', e.target.value)}
                                    placeholder="#000000"
                                    className="flex-1"
                                />
                            </div>
                            {errors?.color && <p className="mt-1 text-sm text-red-500">{errors.color}</p>}
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-4 flex justify-end">
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Loading...' : tag ? 'Update Tag' : 'Create Tag'}
                    </Button>
                </div>
            </form>
        </div>
    );
}
