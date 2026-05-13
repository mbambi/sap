import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 6. SALES & DISTRIBUTION (SD)
// =====================================================================

test.describe('Sales & Distribution', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  // ── Sales Orders ──
  test('sales orders page loads', async ({ page }) => {
    await navigateTo(page, '/sales/orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Sales Order/i).first()).toBeVisible();
  });

  test('sales orders - create form opens', async ({ page }) => {
    await navigateTo(page, '/sales/orders');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Customer|Material|Quantity/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('sales orders - can click on existing SO row', async ({ page }) => {
    await navigateTo(page, '/sales/orders');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
    }
  });

  // ── Deliveries ──
  test('deliveries page loads', async ({ page }) => {
    await navigateTo(page, '/sales/deliveries');
    await expectPageLoaded(page);
    await expect(page.getByText(/Deliver/i).first()).toBeVisible();
  });

  // ── Invoices ──
  test('invoices page loads', async ({ page }) => {
    await navigateTo(page, '/sales/invoices');
    await expectPageLoaded(page);
    await expect(page.getByText(/Invoice/i).first()).toBeVisible();
  });
});
