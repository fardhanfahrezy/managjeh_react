import { Breadcrumbs } from '@/components/breadcrumbs';
import { useOffline } from '@/components/offline/offline-provider';
import { SyncStatusBadge } from '@/components/offline/sync-status-badge';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const {
        syncState,
        pendingCount,
        conflictCount,
        lastError,
        openConflictDialog,
        syncNow,
    } = useOffline();

    const handleBadgeClick =
        syncState === 'conflict'
            ? openConflictDialog
            : syncState === 'session_expired'
              ? () => {
                    window.location.href = '/login';
                }
              : syncState === 'error' || syncState === 'pending'
                ? () => {
                      void syncNow(true);
                  }
                : undefined;

    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 shrink-0" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <SyncStatusBadge
                state={syncState}
                pendingCount={pendingCount}
                conflictCount={conflictCount}
                lastError={lastError}
                onClick={handleBadgeClick}
                className="shrink-0"
            />
        </header>
    );
}
