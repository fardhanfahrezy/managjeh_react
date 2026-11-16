import { createInertiaApp } from '@inertiajs/react';
import { lazy, Suspense, type ReactNode } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';

const LazyAuthLayout = lazy(() => import('@/layouts/auth-layout'));
const LazySettingsLayout = lazy(() => import('@/layouts/settings/layout'));
const LazyToaster = lazy(async () => {
    const module = await import('@/components/ui/sonner');

    return { default: module.Toaster };
});

function AuthLayoutWrapper({ children }: { children: ReactNode }) {
    return (
        <Suspense fallback={null}>
            <LazyAuthLayout>{children}</LazyAuthLayout>
        </Suspense>
    );
}

function SettingsLayoutWrapper({ children }: { children: ReactNode }) {
    return (
        <Suspense fallback={null}>
            <LazySettingsLayout>{children}</LazySettingsLayout>
        </Suspense>
    );
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayoutWrapper;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayoutWrapper];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Suspense fallback={null}>
                    <LazyToaster />
                </Suspense>
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
