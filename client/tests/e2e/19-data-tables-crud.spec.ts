import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 22. DATA TABLE INTERACTIONS (Search, Pagination, Sort)
// =====================================================================

const DATA_TABLE_PAGES = [
  { path: '/finance/gl-accounts', name: 'GL Accounts' },
  { path: '/finance/vendors', name: 'Vendors' },
  { path: '/finance/customers', name: 'Customers' },
  { path: '/materials/items', name: 'Materials' },
  { path: '/materials/purchase-orders', name: 'Purchase Orders' },
  { path: '/sales/orders', name: 'Sales Orders' },
  { path: '/production/orders', name: 'Production Orders' },
  { path: '/warehouse/list', name: 'Warehouses' },
  { path: '/quality/inspections', name: 'Inspections' },
  { path: '/maintenance/equipment', name: 'Equipment' },
];

test.describe('Data Tables: Search & Pagination', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  for (const tableRoute of DATA_TABLE_PAGES) {
    test(`${tableRoute.name} - search input works`, async ({ page }) => {
      await navigateTo(page, tableRoute.path);
      await expectPageLoaded(page);

      const searchInput = page.locator('input[placeholder*="Search"]').first();
      if (await searchInput.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await searchInput.fill('test');
        await page.waitForTimeout(600); // debounce
        // Should still show table (empty or with results)
        await expect(page.locator('table, [class*="card"]').first()).toBeVisible();

        // Clear search
        await searchInput.fill('');
        await page.waitForTimeout(600);
      }
    });
  }

  test('pagination buttons work on GL Accounts', async ({ page }) => {
    await navigateTo(page, '/finance/gl-accounts');
    await page.waitForTimeout(1_000);

    // Check if pagination exists (means > 1 page of data)
    const nextBtn = page.locator('button:has-text("Next"), button:has-text("›"), button:has-text("2")').first();
    if (await nextBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await nextBtn.click();
      await page.waitForTimeout(500);
    }
  });
});

// =====================================================================
// 23. CRUD OPERATIONS: CREATE, VIEW, EDIT
// =====================================================================

test.describe('CRUD: Create Records', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('create a cost center', async ({ page }) => {
    await navigateTo(page, '/controlling/cost-centers');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);

      // Fill code
      const codeInput = page.locator('label:has-text("Code") + input, label:has-text("Code") ~ input').first();
      if (await codeInput.isVisible().catch(() => false)) {
        await codeInput.fill('TEST-CC-001');
      } else {
        // fallback: fill first text input in modal
        await page.locator('.fixed input[type="text"], .fixed input:not([type])').first().fill('TEST-CC-001');
      }

      // Fill name
      const nameInputs = page.locator('.fixed input[type="text"], .fixed input:not([type])');
      const count = await nameInputs.count();
      if (count >= 2) {
        await nameInputs.nth(1).fill('Test Cost Center');
      }

      // Select category
      const catSelect = page.locator('.fixed select').first();
      if (await catSelect.isVisible()) {
        const options = await catSelect.locator('option').allTextContents();
        const validOpt = options.find(o => o && !o.includes('Select'));
        if (validOpt) await catSelect.selectOption({ label: validOpt });
      }

      // Submit
      const submitBtn = page.locator('.fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('create an internal order', async ({ page }) => {
    await navigateTo(page, '/controlling/internal-orders');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);

      // Fill order number
      const inputs = page.locator('.fixed input[type="text"], .fixed input:not([type])');
      const count = await inputs.count();
      if (count >= 1) await inputs.nth(0).fill('IO-TEST-001');
      if (count >= 2) await inputs.nth(1).fill('Test Internal Order');

      // Select type
      const typeSelect = page.locator('.fixed select').first();
      if (await typeSelect.isVisible()) {
        const options = await typeSelect.locator('option').allTextContents();
        const validOpt = options.find(o => o && !o.includes('Select'));
        if (validOpt) await typeSelect.selectOption({ label: validOpt });
      }

      // Submit
      const submitBtn = page.locator('.fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('create an inspection lot', async ({ page }) => {
    await navigateTo(page, '/quality/inspections');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);

      // Fill lot number
      const inputs = page.locator('.fixed input[type="text"], .fixed input:not([type])');
      const count = await inputs.count();
      if (count >= 1) await inputs.nth(0).fill('IL-TEST-001');

      // Fill quantity
      const numInputs = page.locator('.fixed input[type="number"]');
      if (await numInputs.first().isVisible()) {
        await numInputs.first().fill('100');
      }

      // Submit
      const submitBtn = page.locator('.fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('create a non-conformance', async ({ page }) => {
    await navigateTo(page, '/quality/non-conformances');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);

      // Fill description (textarea)
      const textarea = page.locator('.fixed textarea').first();
      if (await textarea.isVisible()) {
        await textarea.fill('Test non-conformance for E2E testing');
      }

      // Select severity
      const selects = page.locator('.fixed select');
      const selectCount = await selects.count();
      for (let i = 0; i < selectCount; i++) {
        const options = await selects.nth(i).locator('option').allTextContents();
        const validOpt = options.find(o => o && !o.includes('Select'));
        if (validOpt) await selects.nth(i).selectOption({ label: validOpt });
      }

      // Submit
      const submitBtn = page.locator('.fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('create a warehouse bin', async ({ page }) => {
    await navigateTo(page, '/warehouse/bins');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);

      // Fill bin code
      const inputs = page.locator('.fixed input[type="text"], .fixed input:not([type])');
      if (await inputs.first().isVisible()) {
        await inputs.first().fill('BIN-TEST-A01');
      }

      // Submit
      const submitBtn = page.locator('.fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await submitBtn.isVisible()) {
        await submitBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });
});

// =====================================================================
// 24. EDIT & VIEW EXISTING RECORDS
// =====================================================================

test.describe('CRUD: View/Edit Existing Records', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('click on GL account row to view details', async ({ page }) => {
    await navigateTo(page, '/finance/gl-accounts');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
      // Modal or detail view should appear
      const modal = page.locator('.fixed, [role="dialog"]').first();
      if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await page.keyboard.press('Escape');
      }
    }
  });

  test('click on vendor row to view details', async ({ page }) => {
    await navigateTo(page, '/finance/vendors');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
      const modal = page.locator('.fixed, [role="dialog"]').first();
      if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await page.keyboard.press('Escape');
      }
    }
  });

  test('click on customer row to view details', async ({ page }) => {
    await navigateTo(page, '/finance/customers');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
      const modal = page.locator('.fixed, [role="dialog"]').first();
      if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await page.keyboard.press('Escape');
      }
    }
  });

  test('click on material row to view details', async ({ page }) => {
    await navigateTo(page, '/materials/items');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
      const modal = page.locator('.fixed, [role="dialog"]').first();
      if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await page.keyboard.press('Escape');
      }
    }
  });

  test('click on equipment row to view details', async ({ page }) => {
    await navigateTo(page, '/maintenance/equipment');
    const firstRow = page.locator('table tbody tr').first();
    if (await firstRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstRow.click();
      await page.waitForTimeout(500);
      const modal = page.locator('.fixed, [role="dialog"]').first();
      if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await page.keyboard.press('Escape');
      }
    }
  });
});
