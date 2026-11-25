import { SaveStatus } from '@/hooks/useAutoSave';
import { cn } from '@/lib/utils';
import { AlertCircle, Check, Cloud, Loader2 } from 'lucide-react';

interface SaveIndicatorProps {
    status: SaveStatus;
    lastSavedAt?: string | null;
    error?: string | null;
    className?: string;
}

export function SaveIndicator({ status, lastSavedAt, error, className }: SaveIndicatorProps) {
    const formatTime = (isoString: string) => {
        const date = new Date(isoString);
        return date.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <div className={cn('flex items-center gap-2 text-sm', className)}>
            {status === 'idle' && lastSavedAt && (
                <>
                    <Cloud className="text-muted-foreground h-4 w-4" />
                    <span className="text-muted-foreground">Saved at {formatTime(lastSavedAt)}</span>
                </>
            )}

            {status === 'saving' && (
                <>
                    <Loader2 className="h-4 w-4 animate-spin text-blue-500" />
                    <span className="text-blue-500">Saving...</span>
                </>
            )}

            {status === 'saved' && (
                <>
                    <Check className="h-4 w-4 text-green-500" />
                    <span className="text-green-500">Saved</span>
                </>
            )}

            {status === 'error' && (
                <>
                    <AlertCircle className="text-destructive h-4 w-4" />
                    <span className="text-destructive" title={error || undefined}>
                        Save failed
                    </span>
                </>
            )}
        </div>
    );
}
