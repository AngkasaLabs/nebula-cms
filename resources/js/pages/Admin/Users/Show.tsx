import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowLeft, Calendar, Edit2, Mail, Shield } from 'lucide-react';

interface Role {
    id: number;
    name: string;
}

interface User {
    id: number;
    name: string;
    email: string;
    roles: Role[];
    bio: string | null;
    avatar: string | null;
    facebook_url: string | null;
    twitter_url: string | null;
    instagram_url: string | null;
    linkedin_url: string | null;
    created_at: string;
}

interface Props {
    user: User;
}

const breadcrumbs = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Users',
        href: route('admin.users.index'),
    },
    {
        title: 'Detail',
    },
];

const roleColors: { [key: string]: string } = {
    admin: 'bg-red-500',
    editor: 'bg-blue-500',
    author: 'bg-green-500',
    user: 'bg-gray-500',
};

export default function Show({ user }: Props) {
    const socialLinks = [
        { url: user.facebook_url, icon: 'facebook.svg', label: 'Facebook' },
        { url: user.twitter_url, icon: 'twitter.svg', label: 'Twitter' },
        { url: user.instagram_url, icon: 'instagram.svg', label: 'Instagram' },
        { url: user.linkedin_url, icon: 'linkedin.svg', label: 'LinkedIn' },
    ].filter((link) => link.url);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`User: ${user.name}`} />

            <div className="mx-auto max-w-5xl p-4">
                {/* Header */}
                <div className="mb-8 flex items-start justify-between">
                    <div>
                        <Link href="/users" className="mb-4 inline-flex items-center text-gray-400 hover:text-gray-200">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali ke daftar pengguna
                        </Link>
                    </div>

                    <Link href={`/users/${user.id}/edit`}>
                        <Button variant="outline" className="border-gray-700 text-gray-200 hover:bg-gray-800">
                            <Edit2 className="mr-2 h-4 w-4" />
                            Edit Pengguna
                        </Button>
                    </Link>
                </div>

                {/* Main Content */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    {/* Left Column - Profile Info */}
                    <div className="md:col-span-1">
                        <div className="rounded-lg border border-gray-800 bg-[#0c1015] p-6">
                            <div className="mb-6 flex flex-col items-center text-center">
                                {user.avatar ? (
                                    <img
                                        src={`/storage/${user.avatar}`}
                                        alt={user.name}
                                        className="mb-4 h-32 w-32 rounded-full object-cover ring-4 ring-gray-800"
                                    />
                                ) : (
                                    <div className="mb-4 flex h-32 w-32 items-center justify-center rounded-full bg-gray-800 text-4xl font-bold text-gray-200 ring-4 ring-gray-700">
                                        {user.name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <h2 className="mb-1 text-2xl font-bold text-gray-200">{user.name}</h2>
                                <div className="mb-4 flex items-center gap-2 text-gray-400">
                                    <Mail className="h-4 w-4" />
                                    {user.email}
                                </div>
                                <div className="flex flex-wrap justify-center gap-2">
                                    {user.roles.map((role) => (
                                        <Badge key={role.id} className={`${roleColors[role.name.toLowerCase()] || 'bg-gray-500'}`}>
                                            <Shield className="mr-1 h-3 w-3" />
                                            {role.name}
                                        </Badge>
                                    ))}
                                </div>
                            </div>

                            {user.bio && (
                                <div className="mt-4 border-t border-gray-800 pt-4">
                                    <h3 className="mb-2 text-sm font-semibold text-gray-400 uppercase">Bio</h3>
                                    <p className="text-gray-200">{user.bio}</p>
                                </div>
                            )}

                            <div className="mt-4 border-t border-gray-800 pt-4">
                                <h3 className="mb-2 text-sm font-semibold text-gray-400 uppercase">Info</h3>
                                <div className="space-y-2">
                                    <div className="flex items-center text-gray-200">
                                        <Calendar className="mr-2 h-4 w-4 text-gray-400" />
                                        Bergabung {format(new Date(user.created_at), 'dd MMMM yyyy', { locale: id })}
                                    </div>
                                </div>
                            </div>

                            {socialLinks.length > 0 && (
                                <div className="mt-4 border-t border-gray-800 pt-4">
                                    <h3 className="mb-2 text-sm font-semibold text-gray-400 uppercase">Media Sosial</h3>
                                    <div className="flex flex-wrap gap-2">
                                        {socialLinks.map((link, index) => (
                                            <a
                                                key={index}
                                                href={link.url || '#'}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center rounded-full bg-gray-800 px-3 py-1 text-gray-200 transition-colors hover:bg-gray-700"
                                            >
                                                <img src={`/icons/${link.icon}`} alt={link.label} className="mr-2 h-4 w-4" />
                                                {link.label}
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Right Column - Activity & Stats */}
                    <div className="md:col-span-2">
                        <div className="rounded-lg border border-gray-800 bg-[#0c1015] p-6">
                            <h3 className="mb-4 text-xl font-semibold text-gray-200">Aktivitas Terbaru</h3>
                            <div className="py-8 text-center text-gray-400">Belum ada aktivitas untuk ditampilkan.</div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
