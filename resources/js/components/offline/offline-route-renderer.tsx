import {
    Component,
    lazy,
    Suspense,
    useEffect,
    useState,
    type ErrorInfo,
    type ReactNode,
} from 'react';
import { useOffline } from '@/components/offline/offline-provider';
import { Button } from '@/components/ui/button';
import {
    addDaysToLocalDate,
    getLocalTodayDate,
    startOfWeekMonday,
} from '@/lib/offline/timezone';
import type { CalendarView, Project } from '@/types/models';

const pageLoaders = {
    dashboard: () => import('@/pages/dashboard'),
    tasks: () => import('@/pages/tasks/index'),
    projects: () => import('@/pages/projects/index'),
    projectShow: () => import('@/pages/projects/show'),
    calendar: () => import('@/pages/calendar/index'),
    aiPlanner: () => import('@/pages/ai-planner/index'),
    aiChat: () => import('@/pages/ai-chat/index'),
};

let preloaded = false;

export function preloadOfflinePageModules(): void {
    if (preloaded || typeof window === 'undefined' || !navigator.onLine) {
        return;
    }
    preloaded = true;

    void Promise.allSettled([
        pageLoaders.dashboard(),
        pageLoaders.tasks(),
        pageLoaders.projects(),
        pageLoaders.projectShow(),
        pageLoaders.calendar(),
        pageLoaders.aiPlanner(),
        pageLoaders.aiChat(),
    ]);
}

const LazyDashboard = lazy(pageLoaders.dashboard);
const LazyTasksIndex = lazy(pageLoaders.tasks);
const LazyProjectsIndex = lazy(pageLoaders.projects);
const LazyProjectShow = lazy(pageLoaders.projectShow);
const LazyCalendarIndex = lazy(pageLoaders.calendar);
const LazyAiPlannerIndex = lazy(pageLoaders.aiPlanner);
const LazyAiChatIndex = lazy(pageLoaders.aiChat);

class OfflineChunkErrorBoundary extends Component<
    { path: string; children: ReactNode },
    { hasError: boolean }
> {
    constructor(props: { path: string; children: ReactNode }) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError(): { hasError: boolean } {
        return { hasError: true };
    }

    componentDidCatch(_error: Error, _info: ErrorInfo): void {
        // Handled cleanly by fallback card below.
    }

    componentDidUpdate(prevProps: { path: string }): void {
        if (prevProps.path !== this.props.path && this.state.hasError) {
            this.setState({ hasError: false });
        }
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="flex flex-1 flex-col items-center justify-center p-6">
                    <div className="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xs">
                        <h2 className="text-base font-semibold text-foreground">
                            Halaman Belum Tersedia Offline
                        </h2>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            Modul halaman ini belum tersimpan di cache
                            perangkat. Hubungkan ke internet sebentar atau muat
                            ulang saat online (pada build produksi PWA).
                        </p>
                        <Button
                            type="button"
                            className="mt-4"
                            onClick={() => window.location.reload()}
                        >
                            Muat Ulang
                        </Button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}

function OfflinePageFallback() {
    return (
        <div className="flex flex-1 flex-col gap-4 p-6 text-sm text-muted-foreground">
            Memuat tampilan dari penyimpanan lokal...
        </div>
    );
}

export function OfflineRouteRenderer({ path }: { path: string }) {
    const { lease, repositories, dataVersion } = useOffline();
    const timezone = lease?.timezone || 'Asia/Jakarta';
    const today = getLocalTodayDate(timezone);

    const parsed = new URL(
        path,
        typeof window !== 'undefined'
            ? window.location.origin
            : 'http://localhost',
    );
    const pathname = parsed.pathname;

    const [resolvedProject, setResolvedProject] = useState<Project | null>(
        null,
    );
    const projectMatch = pathname.match(/^\/projects\/([^/]+)$/);
    const projectParam = projectMatch ? projectMatch[1] : null;

    useEffect(() => {
        if (!projectParam || !repositories) {
            return;
        }

        let cancelled = false;
        async function loadProject() {
            const list = await repositories!.projects.listWithProgress();
            const found = list.find(
                (p) => String(p.id) === projectParam || p.uuid === projectParam,
            );
            if (!cancelled) {
                setResolvedProject(found ?? null);
            }
        }
        void loadProject();
        return () => {
            cancelled = true;
        };
    }, [projectParam, repositories, dataVersion]);

    const renderView = () => {
        if (pathname === '/tasks') {
            return (
                <LazyTasksIndex
                    tasks={[]}
                    projects={[]}
                    filters={{
                        status: parsed.searchParams.get('status') ?? '',
                        priority: parsed.searchParams.get('priority') ?? '',
                        project_id: parsed.searchParams.get('project_id') ?? '',
                        due_date: parsed.searchParams.get('due_date') ?? '',
                    }}
                />
            );
        }

        if (pathname === '/projects') {
            return (
                <LazyProjectsIndex
                    projects={[]}
                    filters={{
                        status: parsed.searchParams.get('status') ?? '',
                    }}
                />
            );
        }

        if (projectParam) {
            if (!resolvedProject) {
                return (
                    <div className="flex flex-1 flex-col gap-4 p-6">
                        <p className="text-sm text-muted-foreground">
                            Memuat detail proyek dari penyimpanan lokal...
                        </p>
                    </div>
                );
            }

            return <LazyProjectShow project={resolvedProject} projects={[]} />;
        }

        if (pathname === '/calendar') {
            const viewParam = parsed.searchParams.get('view');
            const view: CalendarView =
                viewParam === 'day' || viewParam === 'month'
                    ? viewParam
                    : 'week';
            const activeDate = parsed.searchParams.get('date') || today;
            const rangeStart =
                view === 'day' ? activeDate : startOfWeekMonday(activeDate);
            const rangeEnd =
                view === 'day'
                    ? activeDate
                    : addDaysToLocalDate(rangeStart, view === 'week' ? 6 : 34);

            return (
                <LazyCalendarIndex
                    view={view}
                    active_date={activeDate}
                    range_start={rangeStart}
                    range_end={rangeEnd}
                    events={[]}
                    task_deadlines={[]}
                    projects={[]}
                    tasks_for_select={[]}
                    conflict_warnings={[]}
                />
            );
        }

        if (pathname === '/ai-planner') {
            return (
                <LazyAiPlannerIndex
                    planning_date={today}
                    timezone={timezone}
                    latest_run={null}
                    recent_runs={[]}
                />
            );
        }

        if (pathname === '/ai-chat') {
            return (
                <LazyAiChatIndex
                    conversations={[]}
                    active_conversation={null}
                    messages={[]}
                    audits={[]}
                    timezone={timezone}
                    ai_preferences={{
                        tone: 'balanced',
                        preferred_study_start: '08:00',
                        preferred_study_end: '21:00',
                        default_focus_minutes: 45,
                        custom_instruction: null,
                    }}
                />
            );
        }

        return (
            <LazyDashboard
                greeting="Halo"
                local_date={today}
                local_date_label={today}
                timezone={timezone}
                today_tasks={[]}
                upcoming_tasks={[]}
                today_events={[]}
                active_projects={[]}
                ai_priorities={null}
                whatsapp_status={{
                    enabled: lease?.whatsapp_enabled ?? false,
                    verified: lease?.whatsapp_verified ?? false,
                    can_receive: Boolean(
                        lease?.whatsapp_enabled && lease?.whatsapp_verified,
                    ),
                    number_masked: lease?.whatsapp_number_masked ?? null,
                    quiet_hours: lease?.quiet_hours ?? '00:00–05:00',
                }}
                completed_summary={{
                    today_count: 0,
                    last_7_days_count: 0,
                    active_count: 0,
                }}
            />
        );
    };

    return (
        <OfflineChunkErrorBoundary path={path}>
            <Suspense fallback={<OfflinePageFallback />}>
                {renderView()}
            </Suspense>
        </OfflineChunkErrorBoundary>
    );
}
