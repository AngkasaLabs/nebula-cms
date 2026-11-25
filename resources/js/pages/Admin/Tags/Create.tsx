import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import Form from './Form';

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Tags',
        href: route('admin.tags.index'),
    },
    {
        title: 'Create',
    },
];
export default function Create() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Tag" />
            <div className="p-4">
                <Form />
            </div>
        </AppLayout>
    );
}
