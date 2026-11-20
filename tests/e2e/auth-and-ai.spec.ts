import { expect, test } from '@playwright/test';

const LOGIN_EMAIL =
    process.env.PLAYWRIGHT_USER_EMAIL || 'fardhanfahrezy@gmail.com';
const LOGIN_PASSWORD =
    process.env.PLAYWRIGHT_USER_PASSWORD || 'ManagjehLocal2026!';

test.describe('Managjeh Browser E2E (Login, AI Chat, Settings AI)', () => {
    test('user can log in cleanly without hydration errors and navigate /ai-chat and /settings/ai', async ({
        page,
    }) => {
        const pageErrors: string[] = [];
        const consoleErrors: string[] = [];
        page.on('pageerror', (err) => {
            pageErrors.push(err.message);
        });
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        // 1. Open /login and sign in
        await page.goto('/login', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('button', { name: 'Log in' }),
        ).toBeVisible();

        await page.getByLabel('Email address').fill(LOGIN_EMAIL);
        await page.getByLabel('Password', { exact: true }).fill(LOGIN_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();

        await page.waitForURL('**/dashboard');
        await expect(page).toHaveURL(/\/dashboard$/);

        // 2. Navigate to /ai-chat via sidebar link (SPA navigation) and verify UI + tabs
        await page.getByRole('link', { name: 'AI Chat' }).click();
        await page.waitForURL('**/ai-chat');
        await expect(
            page.getByRole('tab', { name: 'Percakapan' }),
        ).toBeVisible();
        await expect(
            page.getByRole('tab', { name: /Riwayat Aksi/ }),
        ).toBeVisible();
        await expect(
            page.getByLabel('Tulis pesan untuk Asisten AI Managjeh'),
        ).toBeVisible();

        await page.getByRole('tab', { name: /Riwayat Aksi/ }).click();
        await expect(
            page.getByRole('heading', { name: 'Riwayat Aksi AI', exact: true }),
        ).toBeVisible();

        await page.getByRole('tab', { name: 'Percakapan' }).click();

        // 3. Check mobile responsiveness (375px) on /ai-chat without horizontal overflow
        await page.setViewportSize({ width: 375, height: 812 });
        const hasOverflow = await page.evaluate(() => {
            return (
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth
            );
        });
        expect(hasOverflow).toBe(false);

        const drawerButton = page.getByRole('button', {
            name: 'Buka riwayat percakapan',
        });
        await expect(drawerButton).toBeVisible();
        await drawerButton.click();
        await expect(
            page.getByRole('button', { name: 'Percakapan Baru' }),
        ).toBeVisible();

        // 4. Visit /settings/ai and verify form controls
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/settings/ai', { waitUntil: 'networkidle' });
        await expect(
            page.getByRole('heading', { name: 'Preferensi Asisten AI' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Simpan Preferensi' }),
        ).toBeVisible();

        expect(pageErrors).toEqual([]);
        expect(consoleErrors).toEqual([]);
    });
});
