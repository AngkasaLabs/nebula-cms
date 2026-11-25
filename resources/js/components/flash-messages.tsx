import { Alert, AlertDescription } from '@/components/ui/alert';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2 } from 'lucide-react';

interface FlashMessagesProps {
    className?: string;
}

/**
 * Renders the flash payload shared by HandleInertiaRequests.
 *
 * Admin actions such as plugin/theme upload redirect back with a flash
 * message; without this banner the result is invisible to the user.
 */
export default function FlashMessages({ className }: FlashMessagesProps) {
    const page = usePage();
    const flash = page.props.flash as { success?: string | null; error?: string | null } | undefined;

    if (!flash?.success && !flash?.error) {
        return null;
    }

    return (
        <div className={cn('grid gap-2', className)}>
            {flash?.success ? (
                <Alert className="border-green-600/40 bg-green-50 text-green-900 dark:border-green-500/40 dark:bg-green-950/40 dark:text-green-100">
                    <CheckCircle2 />
                    <AlertDescription className="text-green-900/90 dark:text-green-100/90">{flash.success}</AlertDescription>
                </Alert>
            ) : null}
            {flash?.error ? (
                <Alert variant="destructive">
                    <AlertCircle />
                    <AlertDescription>{flash.error}</AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}
