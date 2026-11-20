import type { CalendarEvent, ConflictWarning } from '@/types/models';

/**
 * Full TypeScript port of `App\Services\Calendar\ScheduleConflictDetector` (`D-23`).
 */
export function annotateScheduleConflicts(
    occurrences: CalendarEvent[],
): CalendarEvent[] {
    const cloned = occurrences.map((item) => ({
        ...item,
        has_conflict: false,
    }));

    for (let i = 0; i < cloned.length; i++) {
        const a = cloned[i];
        if (a.is_all_day || !a.starts_at || !a.ends_at) {
            continue;
        }

        const aStart = new Date(a.starts_at).getTime();
        const aEnd = new Date(a.ends_at).getTime();

        for (let j = i + 1; j < cloned.length; j++) {
            const b = cloned[j];
            if (b.is_all_day || !b.starts_at || !b.ends_at) {
                continue;
            }

            const bStart = new Date(b.starts_at).getTime();
            const bEnd = new Date(b.ends_at).getTime();

            if (aStart < bEnd && aEnd > bStart) {
                cloned[i].has_conflict = true;
                cloned[j].has_conflict = true;
            }
        }
    }

    return cloned;
}

export function findConflictsForInterval(
    startsAtUtcIso: string,
    endsAtUtcIso: string,
    occurrences: CalendarEvent[],
    excludeUuid?: string | null,
): ConflictWarning[] {
    const startMs = new Date(startsAtUtcIso).getTime();
    const endMs = new Date(endsAtUtcIso).getTime();
    const conflicts: ConflictWarning[] = [];

    for (const occ of occurrences) {
        if (occ.is_all_day || !occ.starts_at || !occ.ends_at) {
            continue;
        }

        if (
            excludeUuid &&
            (occ.uuid === excludeUuid || occ.series_uuid === excludeUuid)
        ) {
            continue;
        }

        const occStart = new Date(occ.starts_at).getTime();
        const occEnd = new Date(occ.ends_at).getTime();

        if (startMs < occEnd && endMs > occStart) {
            conflicts.push({
                id: occ.id,
                title: occ.title,
                starts_at_local: occ.starts_at_local,
                ends_at_local: occ.ends_at_local,
            });
        }
    }

    return conflicts;
}
