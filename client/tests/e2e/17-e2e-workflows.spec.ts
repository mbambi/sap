import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 17. FULL ERP WORKFLOW: PROCUREMENT CYCLE (PR → PO → GR → Invoice)
// =====================================================================

test.describe('End-to-End: Procurement Cycle', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('step 1: view existing purchase orders', async ({ page }) => {
    await navigateTo(page, '/materials/purchase-orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Purchase Order/i).first()).toBeVisible();
    // Wait for table to load
    await page.waitForTimeout(1_000);
  });

  test('step 2: create a purchase order', async ({ page }) => {
    await navigateTo(page, '/materials/purchase-orders');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await page.waitForTimeout(500);

      // Fill vendor (select first available)
      const vendorSelect = page.locator('select').first();
      if (await vendorSelect.isVisible()) {
        const options = await vendorSelect.locator('option').allTextContents();
        const validOption = options.find(o => o && !o.includes('Select'));
        if (validOption) await vendorSelect.selectOption({ label: validOption });
      }

      // Fill line item - material
      const materialInputs = page.locator('select, input[type="text"]');
      // Try to fill quantity
      const qtyInput = page.locator('input[type="number"]').first();
      if (await qtyInput.isVisible()) {
        await qtyInput.fill('10');
      }

      // Submit
      const submitBtn = page.locator('button:has-text("Create"), button:has-text("Save"), button:has-text("Submit")').last();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('step 3: view goods receipts', async ({ page }) => {
    await navigateTo(page, '/materials/goods-receipts');
    await expectPageLoaded(page);
    await expect(page.getByText(/Goods Receipt/i).first()).toBeVisible();
  });

  test('step 4: check AP for supplier invoices', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    await expectPageLoaded(page);
    await expect(page.getByText(/Accounts Payable|Payable/i).first()).toBeVisible();
    // Switch to invoices tab
    const invTab = page.getByText(/Invoice/i).first();
    if (await invTab.isVisible()) await invTab.click();
    await page.waitForTimeout(500);
  });

  test('step 5: check inventory after GR', async ({ page }) => {
    await navigateTo(page, '/materials/inventory');
    await expectPageLoaded(page);
    await expect(page.getByText(/Inventory|Stock/i).first()).toBeVisible();
  });
});

// =====================================================================
// 18. FULL ERP WORKFLOW: SALES CYCLE (SO → Delivery → Invoice)
// =====================================================================

test.describe('End-to-End: Sales Cycle', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('step 1: view existing sales orders', async ({ page }) => {
    await navigateTo(page, '/sales/orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Sales Order/i).first()).toBeVisible();
  });

  test('step 2: create a sales order', async ({ page }) => {
    await navigateTo(page, '/sales/orders');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await page.waitForTimeout(500);

      // Fill customer
      const customerSelect = page.locator('select').first();
      if (await customerSelect.isVisible()) {
        const options = await customerSelect.locator('option').allTextContents();
        const validOption = options.find(o => o && !o.includes('Select'));
        if (validOption) await customerSelect.selectOption({ label: validOption });
      }

      // Fill quantity
      const qtyInput = page.locator('input[type="number"]').first();
      if (await qtyInput.isVisible()) {
        await qtyInput.fill('5');
      }

      // Submit
      const submitBtn = page.locator('button:has-text("Create"), button:has-text("Save"), button:has-text("Submit")').last();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('step 3: view deliveries', async ({ page }) => {
    await navigateTo(page, '/sales/deliveries');
    await expectPageLoaded(page);
    await expect(page.getByText(/Deliver/i).first()).toBeVisible();
  });

  test('step 4: view invoices', async ({ page }) => {
    await navigateTo(page, '/sales/invoices');
    await expectPageLoaded(page);
    await expect(page.getByText(/Invoice/i).first()).toBeVisible();
  });

  test('step 5: check AR for customer invoices', async ({ page }) => {
    await navigateTo(page, '/finance/ar');
    await expectPageLoaded(page);
    await expect(page.getByText(/Accounts Receivable|Receivable/i).first()).toBeVisible();
  });
});

// =====================================================================
// 19. FULL ERP WORKFLOW: PRODUCTION CYCLE (BOM → Prod Order → Schedule)
// =====================================================================

test.describe('End-to-End: Production Cycle', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('step 1: view BOMs', async ({ page }) => {
    await navigateTo(page, '/production/boms');
    await expectPageLoaded(page);
    await expect(page.getByText(/BOM|Bill of Material/i).first()).toBeVisible();
  });

  test('step 2: view production orders', async ({ page }) => {
    await navigateTo(page, '/production/orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Production Order/i).first()).toBeVisible();
  });

  test('step 3: view scheduling board', async ({ page }) => {
    await navigateTo(page, '/production/scheduling');
    await expectPageLoaded(page);
    await expect(page.getByText(/Schedul|Capacity/i).first()).toBeVisible();
  });

  test('step 4: run MRP', async ({ page }) => {
    await navigateTo(page, '/mrp');
    await expectPageLoaded(page);
    const runBtn = page.locator('button:has-text("Run MRP"), button:has-text("Run")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      await page.waitForTimeout(3_000);
    }
  });

  test('step 5: check planned orders after MRP', async ({ page }) => {
    await navigateTo(page, '/mrp');
    const tab = page.getByText(/Planned Order/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(1_000);
    }
  });

  test('step 6: check operations dashboard', async ({ page }) => {
    await navigateTo(page, '/operations/dashboard');
    await expectPageLoaded(page);
    await expect(page.getByText(/Operations|OEE|Throughput/i).first()).toBeVisible();
  });
});
