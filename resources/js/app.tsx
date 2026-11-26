import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { toast } from 'sonner';
import { ErrorBoundary } from './components/error-boundary';
import { Toaster } from './components/ui/sonner';
import { AppearanceProvider } from './contexts/appearance-context';
import { resolveInertiaPage } from './lib/resolve-inertia-page';

const appName = import.meta.env.VITE_APP_NAME || 'NebulaCMS';

interface InertiaInvalidResponse {
    request?: {
        responseURL?: string;
    };
    config?: {
        url?: string;
    };
}

interface InertiaInvalidResponse {
    request?: {
        responseURL?: string;
    };
    config?: {
        url?: string;
    };
}

interface FlashPayload {
    success?: string | null;
    error?: string | null;
}

// Inertia's success event fires for redirects and visits, so flash messages can be
// shown without a hook and without rendering anything outside the Inertia tree.
router.on('success', (event) => {
    const flash = (event.detail.page.props as { flash?: FlashPayload }).flash;

    if (!flash) {
        return;
    }

    if (flash.success) {
        toast.success(flash.success);
    }

    if (flash.error) {
        toast.error(flash.error);
    }
});

// Prevent non-Inertia HTML responses from rendering in a modal overlay (e.g. Blade templates)
router.on('invalid', (event) => {
    if (event.detail.response.status === 200) {
        event.preventDefault();
        const response = event.detail.response as InertiaInvalidResponse;
        const redirectUrl = response.request?.responseURL || response.config?.url || '/';
        window.location.href = redirectUrl;
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: resolveInertiaPage,
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <AppearanceProvider>
                    <App {...props} />
                    <Toaster position="top-right" richColors closeButton />
                </AppearanceProvider>
            </ErrorBoundary>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
