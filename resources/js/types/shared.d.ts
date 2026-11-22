import type { AdminNavigationItem, Auth } from './index';

interface ZiggyConfigLike {
    location: string;
    [key: string]: unknown;
}

declare interface SharedData {
    name: string;
    locale: string;
    translations: {
        posts: Record<string, string>;
        common: Record<string, string>;
    };
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: ZiggyConfigLike;
    sidebarOpen: boolean;
    adminNavigation: AdminNavigationItem[];
    storeUpdates?: {
        count: number;
        items: Array<{ slug: string; type: string; latest: string | null }>;
        checked_at: string | null;
    } | null;
    [key: string]: unknown;
}

export type { SharedData };
