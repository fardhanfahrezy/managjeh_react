import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useOffline } from '@/components/offline/offline-provider';
import { OfflineRouteRenderer } from '@/components/offline/offline-route-renderer';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { offlineRoute } = useOffline();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {offlineRoute !== null ? (
                    <OfflineRouteRenderer path={offlineRoute} />
                ) : (
                    children
                )}
            </AppContent>
        </AppShell>
    );
}
