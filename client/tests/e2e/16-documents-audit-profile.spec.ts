import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 16. DOCUMENTS, AUDIT, PROFILE, UTILITIES, API PLAYGROUND
// =====================================================================

test.describe('Documents', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('documents page loads', async ({ page }) => {
    await navigateTo(page, '/documents');
    await expectPageLoaded(page);
    await expect(page.getByText(/Document/i).first()).toBeVisible();
  });
});

test.describe('Audit', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('audit page loads', async ({ page }) => {
    await navigateTo(page, '/audit');
    await expectPageLoaded(page);
    await expect(page.getByText(/Audit/i).first()).toBeVisible();
  });

  test('audit - log entries visible or empty state', async ({ page }) => {
    await navigateTo(page, '/audit');
    await page.waitForTimeout(1_000);
    const content = page.getByText(/Action|User|Timestamp|No records/i).first();
    await expect(content).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Profile & Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('profile page loads', async ({ page }) => {
    await navigateTo(page, '/profile');
    await expectPageLoaded(page);
    await expect(page.getByText(/Profile|student/i).first()).toBeVisible();
  });

  test('profile - overview tab', async ({ page }) => {
    await navigateTo(page, '/profile');
    const tab = page.getByText(/Overview/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('profile - settings tab', async ({ page }) => {
    await navigateTo(page, '/profile');
    const tab = page.getByText(/Setting/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
      // Should see form fields
      await expect(page.getByText(/Name|Email|Theme|Department/i).first()).toBeVisible();
    }
  });

  test('profile - security tab with password change', async ({ page }) => {
    await navigateTo(page, '/profile');
    const tab = page.getByText(/Security/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Password|Current|New/i).first()).toBeVisible();
    }
  });

  test('profile settings page loads', async ({ page }) => {
    await navigateTo(page, '/profile/settings');
    await expectPageLoaded(page);
  });
});

test.describe('Utilities', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('API playground loads', async ({ page }) => {
    await navigateTo(page, '/tools/api-playground');
    await expectPageLoaded(page);
    await expect(page.getByText(/API|Playground|REST/i).first()).toBeVisible();
  });

  test('API playground - can select method and enter URL', async ({ page }) => {
    await navigateTo(page, '/tools/api-playground');
    const methodSelect = page.locator('select').first();
    if (await methodSelect.isVisible()) {
      await methodSelect.selectOption('GET');
    }
    const urlInput = page.locator('input[type="text"], input[placeholder*="URL"], input[placeholder*="endpoint"]').first();
    if (await urlInput.isVisible()) {
      await urlInput.fill('/api/materials');
    }
  });
});
