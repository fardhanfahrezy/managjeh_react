import {
    addDaysToLocalDate,
    addMonthsNoOverflow,
    diffDaysBetweenLocalDates,
    formatUtcIsoToLocalDate,
    formatUtcIsoToLocalDateTime,
    getDayOfWeekForLocalDate,
    getLocalDayBoundsUtc,
    localDateTimeToUtcIso,
    startOfWeekMonday,
} from '@/lib/offline/timezone';
import type {
    LocalCalendarEventRecord,
    LocalProjectRecord,
    LocalTaskRecord,
} from '@/lib/offline/types';
import type { CalendarEvent } from '@/types/models';

const DAY_MAP: Record<string, number> = {
    SU: 0,
    MO: 1,
    TU: 2,
    WE: 3,
    TH: 4,
    FR: 5,
    SA: 6,
};

interface ParsedRrule {
    freq: 'DAILY' | 'WEEKLY' | 'MONTHLY';
    interval: number;
    byday: number[];
}

export function stableLocalNumericId(uuid: string): number {
    let hash = 0;
    for (let i = 0; i < uuid.length; i++) {
        hash = (hash * 31 + uuid.charCodeAt(i)) | 0;
    }
    const positive = Math.abs(hash) || 1;
    return -positive;
}

export function resolveNumericId(
    serverId: number | null,
    uuid: string,
): number {
    return serverId ?? stableLocalNumericId(uuid);
}

function parseRrule(rrule: string): ParsedRrule | null {
    const parts = rrule.trim().toUpperCase().split(';');
    let freq: ParsedRrule['freq'] | null = null;
    let interval = 1;
    const byday: number[] = [];

    for (const part of parts) {
        const eqIdx = part.indexOf('=');
        if (eqIdx === -1) {
            continue;
        }

        const key = part.slice(0, eqIdx);
        const value = part.slice(eqIdx + 1);

        if (
            key === 'FREQ' &&
            (value === 'DAILY' || value === 'WEEKLY' || value === 'MONTHLY')
        ) {
            freq = value;
        } else if (key === 'INTERVAL' && /^\d+$/.test(value)) {
            const parsedInterval = Number.parseInt(value, 10);
            if (parsedInterval >= 1) {
                interval = parsedInterval;
            }
        } else if (key === 'BYDAY') {
            for (const code of value.split(',')) {
                if (code in DAY_MAP) {
                    byday.push(DAY_MAP[code]);
                }
            }
        }
    }

    if (!freq) {
        return null;
    }

    return {
        freq,
        interval,
        byday: Array.from(new Set(byday)),
    };
}

function generateCandidateDates(
    anchorDate: string,
    rangeStartDate: string,
    effectiveEndDate: string,
    parsed: ParsedRrule,
): string[] {
    const dates: string[] = [];
    const maxIterations = 400;
    let iterations = 0;

    if (parsed.freq === 'DAILY') {
        let cursor = anchorDate;
        while (cursor <= effectiveEndDate && iterations < maxIterations) {
            if (cursor >= rangeStartDate) {
                dates.push(cursor);
            }
            cursor = addDaysToLocalDate(cursor, parsed.interval);
            iterations++;
        }
        return dates;
    }

    if (parsed.freq === 'WEEKLY') {
        const targetWeekdays =
            parsed.byday.length > 0
                ? parsed.byday
                : [getDayOfWeekForLocalDate(anchorDate)];
        let weekStart = startOfWeekMonday(anchorDate);

        while (weekStart <= effectiveEndDate && iterations < maxIterations) {
            for (let offset = 0; offset < 7; offset++) {
                const day = addDaysToLocalDate(weekStart, offset);
                if (day < anchorDate || day > effectiveEndDate) {
                    continue;
                }
                if (
                    day >= rangeStartDate &&
                    targetWeekdays.includes(getDayOfWeekForLocalDate(day))
                ) {
                    dates.push(day);
                }
            }
            weekStart = addDaysToLocalDate(weekStart, parsed.interval * 7);
            iterations++;
        }
        return dates;
    }

    // MONTHLY (matching Carbon's addMonthsNoOverflow)
    let step = 0;
    while (iterations < maxIterations) {
        const candidate = addMonthsNoOverflow(
            anchorDate,
            step * parsed.interval,
        );
        if (candidate > effectiveEndDate) {
            break;
        }
        if (candidate >= rangeStartDate) {
            dates.push(candidate);
        }
        step++;
        iterations++;
    }

    return dates;
}

function overlapsRange(
    event: LocalCalendarEventRecord,
    rangeStartDate: string,
    rangeEndDate: string,
    timezone: string,
): boolean {
    if (event.is_all_day) {
        if (!event.start_date) {
            return false;
        }
        const eventEnd = event.end_date ?? event.start_date;
        return event.start_date <= rangeEndDate && eventEnd >= rangeStartDate;
    }

    if (!event.starts_at || !event.ends_at) {
        return false;
    }

    const { startUtc } = getLocalDayBoundsUtc(rangeStartDate, timezone);
    const { endUtc } = getLocalDayBoundsUtc(rangeEndDate, timezone);

    const eventStartMs = new Date(event.starts_at).getTime();
    const eventEndMs = new Date(event.ends_at).getTime();

    return eventStartMs <= endUtc.getTime() && eventEndMs >= startUtc.getTime();
}

function getAnchorLocalDate(
    event: LocalCalendarEventRecord,
    timezone: string,
): string | null {
    if (event.is_all_day) {
        return event.start_date;
    }

    return formatUtcIsoToLocalDate(event.starts_at, timezone);
}

/**
 * Full TypeScript port of `App\Services\Calendar\RecurrenceExpanderService` (`D-23`).
 */
export function expandCalendarEvents(
    allEvents: LocalCalendarEventRecord[],
    rangeStartDate: string,
    rangeEndDate: string,
    timezone: string,
    projectsByUuid: Map<string, LocalProjectRecord> = new Map(),
    tasksByUuid: Map<string, LocalTaskRecord> = new Map(),
): CalendarEvent[] {
    const activeEvents = allEvents.filter((event) => event.deleted_at === null);

    const overridesByParentUuid = new Map<
        string,
        Map<string, LocalCalendarEventRecord>
    >();

    for (const event of activeEvents) {
        if (event.parent_event_uuid && event.recurrence_id) {
            let map = overridesByParentUuid.get(event.parent_event_uuid);
            if (!map) {
                map = new Map();
                overridesByParentUuid.set(event.parent_event_uuid, map);
            }
            map.set(event.recurrence_id, event);
        }
    }

    const formatOccurrence = (
        master: LocalCalendarEventRecord,
        source: LocalCalendarEventRecord,
        recurrenceId: string | null,
        isOverride: boolean,
    ): CalendarEvent => {
        const masterId = resolveNumericId(master.server_id, master.uuid);
        const sourceId = resolveNumericId(source.server_id, source.uuid);
        const project = source.project_uuid
            ? projectsByUuid.get(source.project_uuid)
            : undefined;
        const task = source.task_uuid
            ? tasksByUuid.get(source.task_uuid)
            : undefined;

        return {
            id: sourceId,
            uuid: source.uuid,
            series_id: masterId,
            series_uuid: master.uuid,
            version: source.version,
            occurrence_key:
                recurrenceId !== null
                    ? `${masterId}:${recurrenceId}`
                    : String(sourceId),
            user_id: source.user_id,
            project_id: project
                ? resolveNumericId(project.server_id, project.uuid)
                : source.project_id,
            project_uuid: source.project_uuid,
            task_id: task
                ? resolveNumericId(task.server_id, task.uuid)
                : source.task_id,
            task_uuid: source.task_uuid,
            parent_event_id: source.parent_event_id,
            parent_event_uuid: source.parent_event_uuid,
            recurrence_id: recurrenceId,
            is_recurring: Boolean(
                master.recurrence_rule && master.recurrence_rule.trim() !== '',
            ),
            is_override: isOverride,
            title: source.title,
            description: source.description,
            starts_at: source.starts_at,
            ends_at: source.ends_at,
            starts_at_local: formatUtcIsoToLocalDateTime(
                source.starts_at,
                timezone,
            ),
            ends_at_local: formatUtcIsoToLocalDateTime(
                source.ends_at,
                timezone,
            ),
            start_date: source.start_date,
            end_date: source.end_date,
            is_all_day: source.is_all_day,
            is_cancelled: source.is_cancelled,
            recurrence_rule: master.recurrence_rule,
            recurrence_until: master.recurrence_until,
            project:
                project && project.deleted_at === null
                    ? {
                          id: resolveNumericId(project.server_id, project.uuid),
                          uuid: project.uuid,
                          name: project.name,
                      }
                    : null,
            task:
                task && task.deleted_at === null
                    ? {
                          id: resolveNumericId(task.server_id, task.uuid),
                          uuid: task.uuid,
                          title: task.title,
                          status: task.status,
                      }
                    : null,
            has_conflict: false,
        };
    };

    const formatShiftedOccurrence = (
        master: LocalCalendarEventRecord,
        occurrenceDate: string,
    ): CalendarEvent => {
        const base = formatOccurrence(master, master, occurrenceDate, false);

        if (master.is_all_day && master.start_date) {
            const spanDays = diffDaysBetweenLocalDates(
                master.start_date,
                master.end_date ?? master.start_date,
            );
            base.start_date = occurrenceDate;
            base.end_date = addDaysToLocalDate(occurrenceDate, spanDays);
            return base;
        }

        if (master.starts_at && master.ends_at) {
            const anchorStartLocal = formatUtcIsoToLocalDateTime(
                master.starts_at,
                timezone,
            );
            const timePart = anchorStartLocal
                ? anchorStartLocal.slice(11, 16)
                : '00:00';
            const durationMs =
                new Date(master.ends_at).getTime() -
                new Date(master.starts_at).getTime();

            const occStartIso = localDateTimeToUtcIso(
                `${occurrenceDate}T${timePart}:00`,
                timezone,
            );
            const occEndIso = new Date(
                new Date(occStartIso).getTime() + durationMs,
            ).toISOString();

            base.starts_at = occStartIso;
            base.ends_at = occEndIso;
            base.starts_at_local = formatUtcIsoToLocalDateTime(
                occStartIso,
                timezone,
            );
            base.ends_at_local = formatUtcIsoToLocalDateTime(
                occEndIso,
                timezone,
            );
        }

        return base;
    };

    const occurrences: CalendarEvent[] = [];

    for (const event of activeEvents) {
        if (event.parent_event_uuid !== null || event.is_cancelled) {
            continue;
        }

        if (!event.recurrence_rule || event.recurrence_rule.trim() === '') {
            if (overlapsRange(event, rangeStartDate, rangeEndDate, timezone)) {
                occurrences.push(formatOccurrence(event, event, null, false));
            }
            continue;
        }

        const parsed = parseRrule(event.recurrence_rule);
        if (!parsed) {
            continue;
        }

        const anchorDate = getAnchorLocalDate(event, timezone);
        if (!anchorDate) {
            continue;
        }

        let effectiveEnd = rangeEndDate;
        if (event.recurrence_until && event.recurrence_until < effectiveEnd) {
            effectiveEnd = event.recurrence_until;
        }

        if (effectiveEnd < anchorDate) {
            continue;
        }

        const exceptions = new Set(event.recurrence_exceptions ?? []);
        const overrides = overridesByParentUuid.get(event.uuid);
        const candidates = generateCandidateDates(
            anchorDate,
            rangeStartDate,
            effectiveEnd,
            parsed,
        );

        for (const occurrenceDate of candidates) {
            if (exceptions.has(occurrenceDate)) {
                continue;
            }

            const override = overrides?.get(occurrenceDate);
            if (override) {
                if (override.is_cancelled) {
                    continue;
                }
                occurrences.push(
                    formatOccurrence(event, override, occurrenceDate, true),
                );
                continue;
            }

            occurrences.push(formatShiftedOccurrence(event, occurrenceDate));
        }
    }

    occurrences.sort((a, b) => {
        const aKey = a.is_all_day
            ? `${a.start_date ?? ''}T00:00`
            : (a.starts_at_local ?? '');
        const bKey = b.is_all_day
            ? `${b.start_date ?? ''}T00:00`
            : (b.starts_at_local ?? '');
        return aKey.localeCompare(bKey);
    });

    return occurrences;
}
