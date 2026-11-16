import { useCallback, useEffect, useState } from 'react';

type HttpExceptionLike = {
    status: number;
    headers: Record<string, string>;
};

/**
 * Tracks a client-side cooldown timer when an Inertia request receives HTTP 429.
 */
export function useRateLimitCooldown() {
    const [cooldownSeconds, setCooldownSeconds] = useState(0);

    useEffect(() => {
        if (cooldownSeconds <= 0) {
            return;
        }

        const timer = window.setInterval(() => {
            setCooldownSeconds((prev) => (prev > 1 ? prev - 1 : 0));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [cooldownSeconds]);

    const handleHttpException = useCallback(
        (response: HttpExceptionLike): boolean | void => {
            if (response.status !== 429) {
                return;
            }

            const rawRetryAfter =
                response.headers['retry-after'] ??
                response.headers['Retry-After'];
            const parsed = Number(rawRetryAfter);
            const seconds =
                Number.isFinite(parsed) && parsed > 0 ? Math.ceil(parsed) : 60;

            setCooldownSeconds(seconds);

            return false;
        },
        [],
    );

    const clearRateLimit = useCallback(() => {
        setCooldownSeconds(0);
    }, []);

    return {
        cooldownSeconds,
        isCoolingDown: cooldownSeconds > 0,
        rateLimitMessage:
            cooldownSeconds > 0
                ? `Terlalu banyak permintaan. Coba lagi dalam ${cooldownSeconds} detik.`
                : null,
        handleHttpException,
        clearRateLimit,
    };
}
