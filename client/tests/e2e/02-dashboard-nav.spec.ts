import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded, expectHeading, doLogin } from './helpers/auth';

// =====================================================================
// 2. DASHBOARD & NAVIGATION
// =====================================================================

test.describe('Dashboard & Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('dashboard loads with KPI cards', async ({ page }) => {
    await expectPageLoaded(page);
    // Should see KPI section
    await expect(page.getByText(/Purchase Orders|Sales Orders|Materials|Production/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('dashboard shows recent orders sections', async ({ page }) => {
    await expect(page.getByText(/Recent Sales Orders|Recent Purchase Orders/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('header search opens with Ctrl+K', async ({ page }) => {
    await page.keyboard.press('Control+k');
    await expect(page.locator('input[placeholder*="Search"]').first()).toBeVisible({ timeout: 5_000 });
    await page.keyboard.press('Escape');
  });

  test('header search finds modules', async ({ page }) => {
    await page.keyboard.press('Control+k');
    const searchInput = page.locator('input[placeholder*="Search"]').first();
    await searchInput.fill('purchase order');
    await page.waitForTimeout(500);
    await expect(page.getByText(/Purchase Order|ME21N/i).first()).toBeVisible();
    await page.keyboard.press('Escape');
  });

  test('notifications panel opens', async ({ page }) => {
    const bellBtn = page.locator('button[aria-label="Notifications"]');
    if (await bellBtn.isVisible()) {
      await bellBtn.click();
      await expect(page.getByText(/Notification/i).first()).toBeVisible();
    }
  });

  test('profile menu shows options', async ({ page }) => {
    // Click user avatar/initials in the header
    const profileBtn = page.locator('header button').last();
    await profileBtn.click();
    await expect(page.getByText('My Profile').or(page.getByText('Sign Out')).first()).toBeVisible({ timeout: 5_000 });
  });

  test('sign out returns to login page', async ({ page }) => {
    const profileBtn = page.locator('header button').last();
    await profileBtn.click();
    await page.getByText('Sign Out').click();
    await expect(page).toHaveURL(/\/login/, { timeout: 10_000 });

    // Re-login and save fresh storageState so later tests aren't affected
    await doLogin(page);
  });
});
