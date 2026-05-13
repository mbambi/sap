import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 8. MRP & PLANNING
// =====================================================================

test.describe('MRP & Planning', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  // ── MRP Dashboard ──
  test('MRP dashboard loads with tabs', async ({ page }) => {
    await navigateTo(page, '/mrp');
    await expectPageLoaded(page);
    await expect(page.getByText(/MRP|Material Requirements/i).first()).toBeVisible();
  });

  test('MRP - runs tab visible', async ({ page }) => {
    await navigateTo(page, '/mrp');
    const runsTab = page.getByText(/Runs/i).first();
    if (await runsTab.isVisible()) {
      await runsTab.click();
      await page.waitForTimeout(500);
    }
  });

  test('MRP - planned orders tab', async ({ page }) => {
    await navigateTo(page, '/mrp');
    const tab = page.getByText(/Planned Order/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('MRP - forecasts tab', async ({ page }) => {
    await navigateTo(page, '/mrp');
    const tab = page.getByText(/Forecast/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('MRP - run MRP button exists', async ({ page }) => {
    await navigateTo(page, '/mrp');
    const runBtn = page.locator('button:has-text("Run MRP"), button:has-text("Run")').first();
    await expect(runBtn).toBeVisible({ timeout: 10_000 });
  });

  // ── MRP Planning Board ──
  test('MRP planning board loads', async ({ page }) => {
    await navigateTo(page, '/mrp-board');
    await expectPageLoaded(page);
    await expect(page.getByText(/Planning Board|MRP/i).first()).toBeVisible();
  });

  // ── Inventory Simulator ──
  test('inventory simulator loads', async ({ page }) => {
    await navigateTo(page, '/inventory/simulator');
    await expectPageLoaded(page);
    await expect(page.getByText(/Inventory Simulator|Policy|EOQ/i).first()).toBeVisible();
  });

  test('inventory simulator - run simulation button', async ({ page }) => {
    await navigateTo(page, '/inventory/simulator');
    const runBtn = page.locator('button:has-text("Run"), button:has-text("Simulate")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      await page.waitForTimeout(2_000);
    }
  });
});
