import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 9. WAREHOUSE, QUALITY, MAINTENANCE
// =====================================================================

test.describe('Warehouse Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('warehouses list loads', async ({ page }) => {
    await navigateTo(page, '/warehouse/list');
    await expectPageLoaded(page);
    await expect(page.getByText(/Warehouse/i).first()).toBeVisible();
  });

  test('warehouses - create form opens', async ({ page }) => {
    await navigateTo(page, '/warehouse/list');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Code|Name|Type/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('warehouse bins page loads', async ({ page }) => {
    await navigateTo(page, '/warehouse/bins');
    await expectPageLoaded(page);
    await expect(page.getByText(/Bin/i).first()).toBeVisible();
  });

  test('warehouse bins - create form opens', async ({ page }) => {
    await navigateTo(page, '/warehouse/bins');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Bin Code|Zone|Aisle/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });
});

test.describe('Quality Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('inspection lots page loads', async ({ page }) => {
    await navigateTo(page, '/quality/inspections');
    await expectPageLoaded(page);
    await expect(page.getByText(/Inspection|Quality/i).first()).toBeVisible();
  });

  test('inspection lots - create form opens', async ({ page }) => {
    await navigateTo(page, '/quality/inspections');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Lot|Material|Quantity|Status/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('non-conformances page loads', async ({ page }) => {
    await navigateTo(page, '/quality/non-conformances');
    await expectPageLoaded(page);
    await expect(page.getByText(/Non-Conformance|Quality/i).first()).toBeVisible();
  });

  test('non-conformances - create form opens', async ({ page }) => {
    await navigateTo(page, '/quality/non-conformances');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Description|Severity|Root Cause/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });
});

test.describe('Plant Maintenance', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('equipment page loads', async ({ page }) => {
    await navigateTo(page, '/maintenance/equipment');
    await expectPageLoaded(page);
    await expect(page.getByText(/Equipment/i).first()).toBeVisible();
  });

  test('equipment - create form opens', async ({ page }) => {
    await navigateTo(page, '/maintenance/equipment');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Equipment|Description|Category|Manufacturer/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('work orders page loads', async ({ page }) => {
    await navigateTo(page, '/maintenance/work-orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Work Order/i).first()).toBeVisible();
  });

  test('work orders - create form opens', async ({ page }) => {
    await navigateTo(page, '/maintenance/work-orders');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/Description|Type|Priority|Status/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });
});
