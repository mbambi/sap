import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 5. MATERIALS MANAGEMENT (MM)
// =====================================================================

test.describe('Materials Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  // ── Materials Master ──
  test('materials list loads', async ({ page }) => {
    await navigateTo(page, '/materials/items');
    await expectPageLoaded(page);
    await expect(page.getByText(/Material/i).first()).toBeVisible();
  });

  test('materials - search for specific material', async ({ page }) => {
    await navigateTo(page, '/materials/items');
    const searchInput = page.locator('input[placeholder*="Search"]').first();
    if (await searchInput.isVisible()) {
      await searchInput.fill('Steel');
      await page.waitForTimeout(500);
    }
  });

  // ── Purchase Orders ──
  test('purchase orders page loads', async ({ page }) => {
    await navigateTo(page, '/materials/purchase-orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Purchase Order/i).first()).toBeVisible();
  });

  test('purchase orders - create form opens with line items', async ({ page }) => {
    await navigateTo(page, '/materials/purchase-orders');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await page.waitForTimeout(500);
      // Should see vendor, line items fields
      await expect(page.getByText(/Vendor|Material|Quantity/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('purchase orders - can click on existing PO row', async ({ page }) => {
    await navigateTo(page, '/materials/purchase-orders');
    const firstRow = page.locator('table tbody tr, [class*="card"]').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
    }
  });

  // ── Goods Receipts ──
  test('goods receipts page loads', async ({ page }) => {
    await navigateTo(page, '/materials/goods-receipts');
    await expectPageLoaded(page);
    await expect(page.getByText(/Goods Receipt/i).first()).toBeVisible();
  });

  // ── Inventory ──
  test('inventory page loads', async ({ page }) => {
    await navigateTo(page, '/materials/inventory');
    await expectPageLoaded(page);
    await expect(page.getByText(/Inventory|Stock/i).first()).toBeVisible();
  });

  // ── Inventory Analytics ──
  test('inventory analytics loads', async ({ page }) => {
    await navigateTo(page, '/inventory/analytics');
    await expectPageLoaded(page);
    await expect(page.getByText(/Inventory|Turnover|Analytics/i).first()).toBeVisible();
  });

  // ── Stock Management ──
  test('stock management page loads with tabs', async ({ page }) => {
    await navigateTo(page, '/inventory/stock');
    await expectPageLoaded(page);
    await expect(page.getByText(/Stock|Inventory/i).first()).toBeVisible();
  });

  test('stock management - goods issue tab', async ({ page }) => {
    await navigateTo(page, '/inventory/stock');
    const tab = page.getByText(/Goods Issue/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('stock management - stock transfer tab', async ({ page }) => {
    await navigateTo(page, '/inventory/stock');
    const tab = page.getByText(/Transfer/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('stock management - stock count tab', async ({ page }) => {
    await navigateTo(page, '/inventory/stock');
    const tab = page.getByText(/Count/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('stock management - movements tab', async ({ page }) => {
    await navigateTo(page, '/inventory/stock');
    const tab = page.getByText(/Movement/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });
});
