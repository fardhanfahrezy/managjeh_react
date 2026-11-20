import { type ReactNode, useEffect, useState } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

export function AppWrapper({ children }: { children: ReactNode }) {
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    return (
        <TooltipProvider delayDuration={0}>
            {children}
            {mounted ? <Toaster /> : null}
        </TooltipProvider>
    );
}
