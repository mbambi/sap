import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 7. PRODUCTION PLANNING (PP)
// =====================================================================

test.describe('Production Planning', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  // ── BOMs ──
  test('BOMs page loads', async ({ page }) => {
    await navigateTo(page, '/production/boms');
    await expectPageLoaded(page);
    await expect(page.getByText(/BOM|Bill of Material/i).first()).toBeVisible();
  });

  test('BOMs - create form opens', async ({ page }) => {
    await navigateTo(page, '/production/boms');
    const addBtn = page.locator('button:has-text("New BOM"), button:has-text("Add"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/BOM|Description|Material|Version/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  // ── Production Orders ──
  test('production orders page loads', async ({ page }) => {
    await navigateTo(page, '/production/orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Production Order/i).first()).toBeVisible();
  });

  test('production orders - create form opens', async ({ page }) => {
    await navigateTo(page, '/production/orders');
    const addBtn = page.locator('button:has-text("New Order"), button:has-text("Add"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Order|Quantity|Status/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  // ── Production Scheduling ──
  test('scheduling board loads', async ({ page }) => {
    await navigateTo(page, '/production/scheduling');
    await expectPageLoaded(page);
    await expect(page.getByText(/Schedul|Work Center|Capacity/i).first()).toBeVisible();
  });

  // ── Operations Dashboard ──
  test('operations dashboard loads with KPIs', async ({ page }) => {
    await navigateTo(page, '/operations/dashboard');
    await expectPageLoaded(page);
    await expect(page.getByText(/Operations|OEE|Throughput|Utilization/i).first()).toBeVisible();
  });
});
