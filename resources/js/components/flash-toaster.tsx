import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

type FlashPayload = {
    success?: string | null;
    error?: string | null;
};

/**
 * Bridges the shared flash payload to toast notifications so server-side
 * success/error messages are visible on every admin page.
 */
export default function FlashToaster() {
    const page = usePage();
    const flash = page.props.flash as FlashPayload | undefined;

    useEffect(() => {
        if (!flash) {
            return;
        }

        if (flash.success) {
            toast.success(flash.success);
        }

        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    return null;
}
