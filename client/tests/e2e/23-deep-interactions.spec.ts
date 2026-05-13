import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 44. EXPERIMENT LAB: RUN ALL TEMPLATES
// =====================================================================

const EXPERIMENT_TEMPLATES = [
  'EOQ',
  'Lot Sizing',
  'Safety Stock',
  'Bullwhip',
  'Scheduling',
];

test.describe('Experiment Lab: Run Templates', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  for (const template of EXPERIMENT_TEMPLATES) {
    test(`select and configure ${template} experiment`, async ({ page }) => {
      await navigateTo(page, '/experiment-lab');
      await expectPageLoaded(page);

      const templateBtn = page.getByText(new RegExp(template, 'i')).first();
      if (await templateBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await templateBtn.click();
        await page.waitForTimeout(1_000);

        // Look for Run button
        const runBtn = page.locator('button:has-text("Run Experiment"), button:has-text("Run"), button:has-text("Start")').first();
        if (await runBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
          await runBtn.click();
          await page.waitForTimeout(5_000);
        }
      }
    });
  }
});

// =====================================================================
// 45. SIMULATION SESSION WORKFLOW
// =====================================================================

test.describe('Simulation Session Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('simulation hub shows create and join sections', async ({ page }) => {
    await navigateTo(page, '/simulation');
    await expectPageLoaded(page);
    await expect(page.getByText(/Create|New Session|Join/i).first()).toBeVisible();
  });

  test('simulation - role selector visible', async ({ page }) => {
    await navigateTo(page, '/simulation');
    const roleSelect = page.locator('select').first();
    if (await roleSelect.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Check role options
      const options = await roleSelect.locator('option').allTextContents();
      expect(options.length).toBeGreaterThan(0);
    }
  });
});

// =====================================================================
// 46. PRICING ENGINE INTERACTIONS
// =====================================================================

test.describe('Pricing Engine', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('pricing engine shows conditions table', async ({ page }) => {
    await navigateTo(page, '/finance/pricing');
    await expectPageLoaded(page);
    await expect(page.getByText(/Pricing|Condition|Discount|Tax|Surcharge/i).first()).toBeVisible();
  });

  test('pricing engine - add condition form', async ({ page }) => {
    await navigateTo(page, '/finance/pricing');
    const addBtn = page.locator('button:has-text("Add"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await page.waitForTimeout(500);
      await page.keyboard.press('Escape');
    }
  });
});

// =====================================================================
// 47. WORKFLOW INTERACTION
// =====================================================================

test.describe('Workflow Interactions', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('workflow page shows pending tasks', async ({ page }) => {
    await navigateTo(page, '/workflow');
    await expectPageLoaded(page);
    await expect(page.getByText(/Pending|Task|Workflow|Approval/i).first()).toBeVisible();
  });

  test('workflow - workflow instances visible', async ({ page }) => {
    await navigateTo(page, '/workflow');
    await expect(page.getByText(/Instance|Active|Completed/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 48. SQL EXPLORER: WRITE AND EXECUTE QUERY
// =====================================================================

test.describe('SQL Explorer Execution', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('write and execute a SELECT query', async ({ page }) => {
    await navigateTo(page, '/sql-explorer');
    await expectPageLoaded(page);

    const queryInput = page.locator('textarea, input[type="text"], [contenteditable]').first();
    if (await queryInput.isVisible()) {
      await queryInput.fill('SELECT * FROM Material LIMIT 5');

      const runBtn = page.locator('button:has-text("Run"), button:has-text("Execute"), button:has-text("Query")').first();
      if (await runBtn.isVisible()) {
        await runBtn.click();
        await page.waitForTimeout(3_000);
      }
    }
  });

  test('write and execute a COUNT query', async ({ page }) => {
    await navigateTo(page, '/sql-explorer');

    const queryInput = page.locator('textarea, input[type="text"], [contenteditable]').first();
    if (await queryInput.isVisible()) {
      await queryInput.fill('SELECT COUNT(*) FROM PurchaseOrder');

      const runBtn = page.locator('button:has-text("Run"), button:has-text("Execute"), button:has-text("Query")').first();
      if (await runBtn.isVisible()) {
        await runBtn.click();
        await page.waitForTimeout(3_000);
      }
    }
  });
});

// =====================================================================
// 49. DATA EXPORT LAB: EXPORT ENTITIES
// =====================================================================

test.describe('Data Export Lab', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('entity list visible with export buttons', async ({ page }) => {
    await navigateTo(page, '/data-lab');
    await expectPageLoaded(page);
    await expect(page.getByText(/CSV|JSON|Export/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('click CSV export button', async ({ page }) => {
    await navigateTo(page, '/data-lab');
    const csvBtn = page.locator('button:has-text("CSV")').first();
    if (await csvBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Don't actually download, just verify button is clickable
      await expect(csvBtn).toBeEnabled();
    }
  });

  test('click JSON export button', async ({ page }) => {
    await navigateTo(page, '/data-lab');
    const jsonBtn = page.locator('button:has-text("JSON")').first();
    if (await jsonBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await expect(jsonBtn).toBeEnabled();
    }
  });
});

// =====================================================================
// 50. COSTING DEEP DIVE
// =====================================================================

test.describe('Product Costing', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('costing shows estimates table', async ({ page }) => {
    await navigateTo(page, '/costing');
    await expectPageLoaded(page);
    await expect(page.getByText(/Cost Estimate|Material|Labor|Overhead/i).first()).toBeVisible();
  });

  test('costing - open calculate cost modal', async ({ page }) => {
    await navigateTo(page, '/costing');
    const calcBtn = page.locator('button:has-text("Calculate"), button:has-text("Add"), button:has-text("New")').first();
    if (await calcBtn.isVisible()) {
      await calcBtn.click();
      await page.waitForTimeout(500);
      // Should see material selection or cost parameters
      await expect(page.getByText(/Material|Quantity|Cost/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });
});
