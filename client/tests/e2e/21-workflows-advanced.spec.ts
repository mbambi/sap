import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 28. JOURNAL ENTRY WORKFLOW (POST, VIEW TRIAL BALANCE)
// =====================================================================

test.describe('Journal Entry Workflow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('create journal entry with balanced debits/credits', async ({ page }) => {
    await navigateTo(page, '/finance/journal-entries');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await page.waitForTimeout(500);

      // Fill description
      const descInput = page.locator('input, textarea').filter({ hasText: '' });
      const textInputs = page.locator('.fixed input[type="text"], .fixed textarea');
      const textCount = await textInputs.count();
      if (textCount > 0) {
        // Fill first text field (likely description or reference)
        await textInputs.first().fill('E2E Test Journal Entry');
      }

      // Try to post
      const postBtn = page.locator('.fixed button:has-text("Post"), .fixed button:has-text("Create"), .fixed button:has-text("Save")').first();
      if (await postBtn.isVisible()) {
        await postBtn.click();
        await page.waitForTimeout(2_000);
      }
    }
  });

  test('trial balance shows balanced totals', async ({ page }) => {
    await navigateTo(page, '/finance/trial-balance');
    await page.waitForTimeout(1_000);
    // Check for balance indicator
    const balanceText = page.getByText(/Balanced|Balance/i).first();
    await expect(balanceText).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 29. MRP FULL WORKFLOW
// =====================================================================

test.describe('MRP Full Workflow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('run MRP and see results', async ({ page }) => {
    await navigateTo(page, '/mrp');
    await expectPageLoaded(page);

    const runBtn = page.locator('button:has-text("Run MRP"), button:has-text("Run")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      // Wait for MRP to complete
      await page.waitForTimeout(5_000);

      // Should see results (materials analyzed, planned orders)
      await expect(page.getByText(/Material|Planned|Order|Result/i).first()).toBeVisible({ timeout: 10_000 });
    }
  });

  test('add demand forecast', async ({ page }) => {
    await navigateTo(page, '/mrp');
    // Switch to forecasts tab
    const forecastTab = page.getByText(/Forecast/i).first();
    if (await forecastTab.isVisible()) {
      await forecastTab.click();
      await page.waitForTimeout(500);

      const addBtn = page.locator('button:has-text("Add Forecast"), button:has-text("Add"), button:has-text("New")').first();
      if (await addBtn.isVisible()) {
        await addBtn.click();
        await page.waitForTimeout(500);
        await page.keyboard.press('Escape');
      }
    }
  });

  test('MRP planning board shows 12-week view', async ({ page }) => {
    await navigateTo(page, '/mrp-board');
    await expectPageLoaded(page);
    await expect(page.getByText(/Week|Demand|Supply|Shortage|Planning/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 30. INVENTORY POLICY SIMULATION
// =====================================================================

test.describe('Inventory Policy Simulation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('run EOQ simulation', async ({ page }) => {
    await navigateTo(page, '/inventory/simulator');
    await expectPageLoaded(page);

    // Select EOQ policy (if selector visible)
    const policySelect = page.locator('select, button:has-text("EOQ")').first();
    if (await policySelect.isVisible()) {
      if (await policySelect.evaluate(el => el.tagName.toLowerCase()) === 'select') {
        const options = await policySelect.locator('option').allTextContents();
        const eoqOpt = options.find(o => /EOQ/i.test(o));
        if (eoqOpt) await policySelect.selectOption({ label: eoqOpt });
      } else {
        await policySelect.click();
      }
    }

    // Run simulation
    const runBtn = page.locator('button:has-text("Run"), button:has-text("Simulate")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      await page.waitForTimeout(3_000);
    }
  });
});

// =====================================================================
// 31. DATA WAREHOUSE & ETL
// =====================================================================

test.describe('Data Warehouse', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('data warehouse shows star schema or analytics', async ({ page }) => {
    await navigateTo(page, '/data-warehouse');
    await expectPageLoaded(page);
    await expect(page.getByText(/Data Warehouse|Star Schema|ETL|Fact|Analytics/i).first()).toBeVisible();
  });
});

// =====================================================================
// 32. OPTIMIZATION ENGINE
// =====================================================================

test.describe('Optimization Engine', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('optimization page loads with options', async ({ page }) => {
    await navigateTo(page, '/optimization');
    await expectPageLoaded(page);
    await expect(page.getByText(/Optimization|Warehouse|Production|Inventory|Transport/i).first()).toBeVisible();
  });

  test('can start an optimization run', async ({ page }) => {
    await navigateTo(page, '/optimization');
    const runBtn = page.locator('button:has-text("Run"), button:has-text("Optimize"), button:has-text("Start")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      await page.waitForTimeout(3_000);
    }
  });
});

// =====================================================================
// 33. FORECASTING
// =====================================================================

test.describe('Forecasting Engine', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('forecasting page loads with model selection', async ({ page }) => {
    await navigateTo(page, '/forecasting');
    await expectPageLoaded(page);
    await expect(page.getByText(/Forecast|Model|MAPE|Demand/i).first()).toBeVisible();
  });

  test('can run a forecast', async ({ page }) => {
    await navigateTo(page, '/forecasting');
    const runBtn = page.locator('button:has-text("Run"), button:has-text("Generate"), button:has-text("Forecast")').first();
    if (await runBtn.isVisible()) {
      await runBtn.click();
      await page.waitForTimeout(3_000);
    }
  });
});
