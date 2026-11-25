import { cn } from '@/lib/utils';
import { type ComponentPropsWithoutRef } from 'react';

/** Huruf "N" untuk layout yang mengharapkan ikon/logo kecil (auth, sheet menu, dll.). */
export default function AppLogoIcon({ className, ...props }: ComponentPropsWithoutRef<'span'>) {
    return (
        <span
            className={cn('inline-flex shrink-0 items-center justify-center leading-none font-semibold tracking-tight', className)}
            aria-hidden
            {...props}
        >
            N
        </span>
    );
}
