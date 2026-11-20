const ID_WEEKDAYS = [
    'Minggu',
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
];

const ID_MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

export function generateUuid(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;
        const value = char === 'x' ? random : (random & 0x3) | 0x8;
        return value.toString(16);
    });
}

function getDateTimePartsInTimezone(
    date: Date,
    timezone: string,
): {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    second: number;
} {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone || 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });

    const parts = formatter.formatToParts(date);
    const read = (type: string): number => {
        const found = parts.find((part) => part.type === type)?.value ?? '0';
        const parsed = Number.parseInt(found, 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    };

    const rawHour = read('hour');

    return {
        year: read('year'),
        month: read('month'),
        day: read('day'),
        hour: rawHour === 24 ? 0 : rawHour,
        minute: read('minute'),
        second: read('second'),
    };
}

function pad2(value: number): string {
    return String(value).padStart(2, '0');
}

export function formatUtcIsoToLocalDateTime(
    utcIso: string | null | undefined,
    timezone: string,
): string | null {
    if (!utcIso) {
        return null;
    }

    const date = new Date(utcIso);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = getDateTimePartsInTimezone(date, timezone);
    return `${parts.year}-${pad2(parts.month)}-${pad2(parts.day)}T${pad2(parts.hour)}:${pad2(parts.minute)}`;
}

export function formatUtcIsoToLocalDate(
    utcIso: string | null | undefined,
    timezone: string,
): string | null {
    const localDateTime = formatUtcIsoToLocalDateTime(utcIso, timezone);
    return localDateTime ? localDateTime.slice(0, 10) : null;
}

export function getLocalTodayDate(
    timezone: string,
    now: Date = new Date(),
): string {
    const parts = getDateTimePartsInTimezone(now, timezone);
    return `${parts.year}-${pad2(parts.month)}-${pad2(parts.day)}`;
}

export function getLocalHour(timezone: string, now: Date = new Date()): number {
    return getDateTimePartsInTimezone(now, timezone).hour;
}

/**
 * Converts a local wall-clock datetime (`YYYY-MM-DDTHH:mm` or `YYYY-MM-DD HH:mm:ss`)
 * in the user's IANA timezone into a UTC ISO-8601 string.
 */
export function localDateTimeToUtcIso(
    localDateTime: string,
    timezone: string,
): string {
    const match = localDateTime
        .trim()
        .match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!match) {
        const parsed = new Date(localDateTime);
        return Number.isNaN(parsed.getTime())
            ? new Date().toISOString()
            : parsed.toISOString();
    }

    const year = Number.parseInt(match[1], 10);
    const month = Number.parseInt(match[2], 10);
    const day = Number.parseInt(match[3], 10);
    const hour = Number.parseInt(match[4], 10);
    const minute = Number.parseInt(match[5], 10);
    const second = Number.parseInt(match[6] ?? '0', 10);

    const targetAsUtcMs = Date.UTC(year, month - 1, day, hour, minute, second);
    let candidateMs = targetAsUtcMs;

    // Two iterations resolve DST transitions cleanly across all IANA zones.
    for (let i = 0; i < 2; i++) {
        const actual = getDateTimePartsInTimezone(
            new Date(candidateMs),
            timezone,
        );
        const actualAsUtcMs = Date.UTC(
            actual.year,
            actual.month - 1,
            actual.day,
            actual.hour,
            actual.minute,
            actual.second,
        );
        candidateMs += targetAsUtcMs - actualAsUtcMs;
    }

    return new Date(candidateMs).toISOString();
}

export function getLocalDayBoundsUtc(
    localDate: string,
    timezone: string,
): { startUtc: Date; endUtc: Date } {
    const startIso = localDateTimeToUtcIso(`${localDate}T00:00:00`, timezone);
    const nextDate = addDaysToLocalDate(localDate, 1);
    const nextStartMs = new Date(
        localDateTimeToUtcIso(`${nextDate}T00:00:00`, timezone),
    ).getTime();

    return {
        startUtc: new Date(startIso),
        endUtc: new Date(nextStartMs - 1),
    };
}

export function parseLocalDate(localDate: string): {
    year: number;
    month: number;
    day: number;
} {
    const [y, m, d] = localDate.split('-').map((v) => Number.parseInt(v, 10));
    return {
        year: y || 1970,
        month: m || 1,
        day: d || 1,
    };
}

export function formatLocalDateParts(
    year: number,
    month: number,
    day: number,
): string {
    return `${year}-${pad2(month)}-${pad2(day)}`;
}

export function addDaysToLocalDate(localDate: string, days: number): string {
    const { year, month, day } = parseLocalDate(localDate);
    const utc = new Date(Date.UTC(year, month - 1, day + days));
    return formatLocalDateParts(
        utc.getUTCFullYear(),
        utc.getUTCMonth() + 1,
        utc.getUTCDate(),
    );
}

export function addMonthsNoOverflow(localDate: string, months: number): string {
    const { year, month, day } = parseLocalDate(localDate);
    const zeroBasedMonth = month - 1 + months;
    const targetYear = year + Math.floor(zeroBasedMonth / 12);
    const targetMonthIndex = ((zeroBasedMonth % 12) + 12) % 12;
    const lastDayOfMonth = new Date(
        Date.UTC(targetYear, targetMonthIndex + 1, 0),
    ).getUTCDate();

    return formatLocalDateParts(
        targetYear,
        targetMonthIndex + 1,
        Math.min(day, lastDayOfMonth),
    );
}

export function diffDaysBetweenLocalDates(
    startDate: string,
    endDate: string,
): number {
    const start = parseLocalDate(startDate);
    const end = parseLocalDate(endDate);
    const startMs = Date.UTC(start.year, start.month - 1, start.day);
    const endMs = Date.UTC(end.year, end.month - 1, end.day);
    return Math.round((endMs - startMs) / 86_400_000);
}

/**
 * Day of week matching Carbon/JS: 0 = Sunday, 1 = Monday, ..., 6 = Saturday.
 */
export function getDayOfWeekForLocalDate(localDate: string): number {
    const { year, month, day } = parseLocalDate(localDate);
    return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

export function startOfWeekMonday(localDate: string): string {
    const dow = getDayOfWeekForLocalDate(localDate);
    const offset = dow === 0 ? -6 : 1 - dow;
    return addDaysToLocalDate(localDate, offset);
}

export function formatIndonesianDateLabel(localDate: string): string {
    const { year, month, day } = parseLocalDate(localDate);
    const dow = getDayOfWeekForLocalDate(localDate);
    const weekdayLabel = ID_WEEKDAYS[dow] ?? '';
    const monthLabel = ID_MONTHS[month - 1] ?? '';
    return `${weekdayLabel}, ${pad2(day)} ${monthLabel} ${year}`;
}
