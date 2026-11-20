import { expect, test } from '@playwright/test';

const LOGIN_EMAIL =
    process.env.PLAYWRIGHT_USER_EMAIL || 'fardhanfahrezy@gmail.com';
const LOGIN_PASSWORD =
    process.env.PLAYWRIGHT_USER_PASSWORD || 'ManagjehLocal2026!';

test.describe('Managjeh Offline-First PWA & Automatic Sync E2E', () => {
    test('supports full offline CRUD, navigation, local AI planner, logout guard, hard reload, and auto-sync on reconnect', async ({
        context,
        page,
    }) => {
        test.setTimeout(60000);
        const suffix = String(Date.now()).slice(-6);
        const offlineTaskTitle = `Tugas Offline E2E ${suffix}`;
        const offlineProjectName = `Proyek Offline E2E ${suffix}`;

        const pageErrors: string[] = [];
        page.on('pageerror', (err) => {
            pageErrors.push(err.message);
        });

        // 1. Login online and wait for initial sync + Service Worker registration.
        await page.goto('/login', { waitUntil: 'networkidle' });
        await page.getByLabel('Email address').fill(LOGIN_EMAIL);
        await page.getByLabel('Password', { exact: true }).fill(LOGIN_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();

        await page.waitForURL('**/dashboard');
        await expect(
            page.getByRole('status').filter({ hasText: /Tersinkronisasi/ }),
        ).toBeVisible({ timeout: 15000 });

        // Ensure Service Worker is active and offline shell is cached.
        await page.evaluate(async () => {
            if ('serviceWorker' in navigator) {
                await navigator.serviceWorker.ready;
            }
        });
        await page.waitForTimeout(1000);

        // 2. Go offline!
        await context.setOffline(true);
        await page.evaluate(() => {
            window.dispatchEvent(new Event('offline'));
        });

        await expect(
            page.getByRole('status').filter({ hasText: /Offline/ }),
        ).toBeVisible({ timeout: 5000 });

        // 3. Navigate to Tasks offline and create a task via Quick-Add.
        await page.getByRole('link', { name: 'Tasks' }).click();
        await expect(
            page.getByRole('heading', { name: 'Tasks', exact: true }),
        ).toBeVisible();

        await page.getByLabel('Judul tugas cepat').fill(offlineTaskTitle);
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page.getByText(offlineTaskTitle)).toBeVisible({
            timeout: 5000,
        });
        await expect(
            page
                .getByRole('status')
                .filter({ hasText: /Offline · \d+ tersimpan lokal/ }),
        ).toBeVisible();

        // 4. Navigate to Projects offline and create a project.
        await page.getByRole('link', { name: 'Projects' }).click();
        await expect(
            page.getByRole('heading', { name: 'Projects', exact: true }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Tambah Project' }).click();
        await page.getByLabel('Nama Project').fill(offlineProjectName);
        await page.getByRole('button', { name: 'Buat Project' }).click();

        await expect(page.getByText(offlineProjectName)).toBeVisible({
            timeout: 5000,
        });

        // 5. Navigate to Calendar offline and verify calendar renders smoothly.
        await page.getByRole('link', { name: 'Calendar' }).click();
        await expect(
            page.getByRole('heading', { name: 'Kalender & Agenda' }),
        ).toBeVisible();

        // 6. Navigate to AI Planner offline and run deterministic local priority planner.
        await page.getByRole('link', { name: 'AI Planner' }).click();
        await expect(
            page.getByRole('heading', { name: 'AI Planner Harian' }),
        ).toBeVisible();
        const localPlanBtn = page.getByRole('button', {
            name: 'Susun Prioritas Lokal (Offline)',
        });
        await expect(localPlanBtn).toBeVisible();
        await localPlanBtn.click();
        await expect(
            page.getByText(/Rencana prioritas lokal \(offline\)/),
        ).toBeVisible({ timeout: 5000 });

        // 7. Navigate to AI Chat offline and verify read-only offline banner & disabled composer.
        await page.getByRole('link', { name: 'AI Chat' }).click();
        await expect(
            page.getByText(/Mode Offline \(Hanya Baca\)/),
        ).toBeVisible();
        await expect(
            page.getByLabel('Tulis pesan untuk Asisten AI Managjeh'),
        ).toBeDisabled();

        // 8. Verify Logout Guard when Outbox has unsynced items (`D-22`).
        await page.locator('[data-test="sidebar-menu-button"]').click();
        await page.locator('[data-test="logout-button"]').click();
        await expect(
            page.getByText('Perubahan Lokal Belum Tersinkronisasi'),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Batal' }).click();

        // 9. Navigate back to Tasks and perform a HARD RELOAD while offline!
        await page.getByRole('link', { name: 'Tasks' }).click();
        await expect(page.getByText(offlineTaskTitle)).toBeVisible();
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.getByText(offlineTaskTitle)).toBeVisible({
            timeout: 10000,
        });

        // 10. Reconnect and verify automatic synchronization pushes Outbox to PostgreSQL without duplicates!
        await context.setOffline(false);
        await page.evaluate(() => {
            window.dispatchEvent(new Event('online'));
        });

        await expect(
            page.getByRole('status').filter({ hasText: /Tersinkronisasi/ }),
        ).toBeVisible({ timeout: 20000 });

        // Verify persisted on server by loading fresh server page.
        await page.goto('/tasks', { waitUntil: 'networkidle' });
        await expect(page.getByText(offlineTaskTitle)).toHaveCount(1, {
            timeout: 10000,
        });

        await page.goto('/projects', { waitUntil: 'networkidle' });
        await expect(page.getByText(offlineProjectName)).toHaveCount(1, {
            timeout: 10000,
        });

        // Cleanup created E2E records cleanly via UI so DB remains tidy.
        await page
            .getByRole('button', {
                name: `Hapus project ${offlineProjectName}`,
            })
            .click();
        await page.getByRole('button', { name: 'Hapus Project' }).click();
        await expect(page.getByText(offlineProjectName)).toHaveCount(0, {
            timeout: 5000,
        });

        await page.goto('/tasks', { waitUntil: 'networkidle' });
        await page
            .getByRole('button', { name: `Hapus tugas ${offlineTaskTitle}` })
            .click();
        await page.getByRole('button', { name: 'Hapus Tugas' }).click();
        await expect(page.getByText(offlineTaskTitle)).toHaveCount(0, {
            timeout: 5000,
        });

        // Wait for cleanup deletions to sync before ending test.
        await expect(
            page.getByRole('status').filter({ hasText: /Tersinkronisasi/ }),
        ).toBeVisible({ timeout: 15000 });

        expect(pageErrors).toEqual([]);
    });
});
