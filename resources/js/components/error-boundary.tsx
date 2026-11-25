import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';
import { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
    children?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null,
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught error:', error, errorInfo);
    }

    public render() {
        if (this.state.hasError) {
            return (
                <div className="bg-background flex min-h-screen flex-col items-center justify-center p-4 text-center">
                    <div className="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20">
                        <AlertTriangle className="h-10 w-10 text-red-600 dark:text-red-400" />
                    </div>
                    <h1 className="mb-2 text-2xl font-bold tracking-tight">Something went wrong</h1>
                    <p className="text-muted-foreground mb-6 max-w-md">
                        The application encountered an unexpected error.
                        {this.state.error && (
                            <span className="bg-muted mt-2 block max-h-32 overflow-auto rounded p-2 text-left font-mono text-xs">
                                {this.state.error.toString()}
                            </span>
                        )}
                    </p>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => window.history.back()}>
                            Go Back
                        </Button>
                        <Button onClick={() => window.location.reload()}>Reload Page</Button>
                        <Button variant="outline" onClick={() => (window.location.href = '/admin/dashboard')}>
                            Dashboard
                        </Button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
