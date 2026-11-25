import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { type AdminNavigationItem, type AdminNavigationSection, type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    AlignJustify,
    BarChart3,
    BookOpen,
    Boxes,
    Calendar,
    Code,
    CreditCard,
    Database,
    Download,
    File,
    FileText,
    Flag,
    Folder,
    Globe,
    Image,
    Languages,
    LayoutGrid,
    LifeBuoy,
    Mail,
    Megaphone,
    MessageSquare,
    Package,
    Paintbrush,
    Puzzle,
    Receipt,
    Route,
    ScrollText,
    Settings,
    Shield,
    ShoppingCart,
    Store,
    Tag,
    Ticket,
    Truck,
    Users,
    Wrench,
    Zap,
} from 'lucide-react';
import { useMemo } from 'react';
import AppLogo from './app-logo';

function can(permissions: string[], name: string): boolean {
    return permissions.includes(name);
}

/**
 * Icon names accepted from plugin manifests. Keep in sync with
 * App\Support\AdminNavigation::ICONS (enforced by a PHP test).
 */
const iconMap = {
    AlignJustify,
    BarChart3,
    BookOpen,
    Boxes,
    Calendar,
    Code,
    CreditCard,
    Database,
    Download,
    File,
    FileText,
    Flag,
    Folder,
    Globe,
    Image,
    Languages,
    LayoutGrid,
    LifeBuoy,
    Mail,
    Megaphone,
    MessageSquare,
    Package,
    Paintbrush,
    Puzzle,
    Receipt,
    Route,
    ScrollText,
    Settings,
    Shield,
    ShoppingCart,
    Store,
    Tag,
    Ticket,
    Truck,
    Users,
    Wrench,
    Zap,
} as const;

/**
 * Mirrors App\Support\AdminNavigation::SECTIONS. The shared payload wins when
 * present; this list only keeps the sidebar working with a stale payload.
 */
const FALLBACK_SECTIONS: AdminNavigationSection[] = [
    { key: 'main', label: null, order: 10 },
    { key: 'content', label: 'Content Management', order: 20 },
    { key: 'commerce', label: 'Commerce', order: 30 },
    { key: 'tools', label: 'Tools', order: 40 },
    { key: 'system', label: 'System', order: 80 },
    { key: 'extensions', label: 'Extensions', order: 90 },
];

/** Above this many links, the Extensions section collapses into one item. */
const EXTENSIONS_COLLAPSE_AFTER = 6;

function toNavItem(item: AdminNavigationItem): NavItem {
    const icon = item.icon ? iconMap[item.icon as keyof typeof iconMap] : undefined;

    return {
        title: item.title,
        href: item.href,
        icon: icon ?? Puzzle,
        badge: item.badge ?? undefined,
        matchPaths: item.match_paths,
        items: item.items,
    };
}

export function AppSidebar() {
    const { auth, updateAvailable, adminNavigation, adminNavigationSections, storeUpdates } = usePage<SharedData>().props;
    const permissions = useMemo(() => auth.permissions ?? [], [auth.permissions]);

    const sections = useMemo(() => {
        const meta = (adminNavigationSections?.length ? adminNavigationSections : FALLBACK_SECTIONS).slice().sort((a, b) => a.order - b.order);

        const grouped = new Map<string, NavItem[]>();
        const push = (section: string, item: NavItem) => {
            const items = grouped.get(section) ?? [];
            items.push(item);
            grouped.set(section, items);
        };

        if (can(permissions, 'view dashboard')) {
            push('main', {
                title: 'Dashboard',
                href: route('admin.dashboard'),
                icon: LayoutGrid,
            });
        }

        if (can(permissions, 'view content')) {
            push('content', {
                title: 'Pages',
                href: route('admin.pages.index'),
                icon: File,
            });
        }

        const postSubs: { title: string; href: string }[] = [];
        if (can(permissions, 'view content')) {
            postSubs.push({ title: 'All Posts', href: route('admin.posts.index') });
        }
        if (can(permissions, 'view categories')) {
            postSubs.push({ title: 'Categories', href: route('admin.categories.index') });
            postSubs.push({ title: 'Tags', href: route('admin.tags.index') });
        }
        if (postSubs.length > 0) {
            push('content', {
                title: 'Posts',
                icon: FileText,
                items: postSubs,
            });
        }

        if (can(permissions, 'view media')) {
            push('content', {
                title: 'Media',
                href: route('admin.media.index'),
                icon: Image,
            });
        }

        if (can(permissions, 'manage menus')) {
            push('content', {
                title: 'Menus',
                href: route('admin.menus.index'),
                icon: AlignJustify,
            });
        }

        if (can(permissions, 'view users')) {
            push('system', {
                title: 'Users',
                href: route('admin.users.index'),
                icon: Users,
            });
        }
        if (can(permissions, 'manage roles')) {
            push('system', {
                title: 'Roles',
                href: route('admin.roles.index'),
                icon: Shield,
            });
        }
        if (can(permissions, 'view audit log')) {
            push('system', {
                title: 'Audit log',
                href: route('admin.audit-log.index'),
                icon: ScrollText,
            });
        }
        if (can(permissions, 'manage themes')) {
            push('system', {
                title: 'Themes',
                href: route('admin.themes.index'),
                icon: Paintbrush,
            });
        }
        if (can(permissions, 'manage plugins')) {
            push('system', {
                title: 'Plugins',
                href: route('admin.plugins.index'),
                icon: Puzzle,
            });
        }
        if (can(permissions, 'store.install')) {
            push('system', {
                title: 'Marketplace',
                href: '/admin/marketplace',
                icon: Store,
                badge: storeUpdates?.count ? String(storeUpdates.count) : undefined,
            });
        }
        if (can(permissions, 'manage settings')) {
            push('system', {
                title: 'Settings',
                href: route('admin.settings.general'),
                icon: Settings,
                badge: updateAvailable?.available === true ? 'New' : undefined,
            });
        }

        for (const item of adminNavigation ?? []) {
            push(item.group, toNavItem(item));
        }

        return meta
            .map((section) => ({ ...section, items: grouped.get(section.key) ?? [] }))
            .filter((section) => section.items.length > 0)
            .map((section) => {
                if (section.key !== 'extensions' || section.items.length <= EXTENSIONS_COLLAPSE_AFTER) {
                    return section;
                }

                // Only collapse flat lists: nested plugin menus stay visible.
                if (section.items.some((item) => item.items && item.items.length > 0)) {
                    return section;
                }

                const links: { title: string; href: string }[] = [];
                for (const item of section.items) {
                    if (item.href) {
                        links.push({ title: item.title, href: item.href });
                    }
                }

                return {
                    ...section,
                    label: null,
                    items: [{ title: 'Extensions', icon: Puzzle, items: links }],
                };
            });
    }, [permissions, updateAvailable, adminNavigation, adminNavigationSections, storeUpdates]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild tooltip="NebulaCMS">
                            <Link href={route('admin.dashboard')} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {sections.map((section) => (
                    <NavMain key={section.key} items={section.items} label={section.label ?? undefined} />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}
