import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 15. EXPERIMENT LAB, SIMULATOR, COSTING, FINANCIAL OPS
// =====================================================================

test.describe('Experiment Lab', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('experiment lab loads', async ({ page }) => {
    await navigateTo(page, '/experiment-lab');
    await expectPageLoaded(page);
    await expect(page.getByText(/Experiment Lab|Research/i).first()).toBeVisible();
  });

  test('experiment lab - template list visible', async ({ page }) => {
    await navigateTo(page, '/experiment-lab');
    await expect(page.getByText(/EOQ|Lot Sizing|Safety Stock|Bullwhip|Scheduling/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('experiment lab - click first template', async ({ page }) => {
    await navigateTo(page, '/experiment-lab');
    const template = page.getByText(/EOQ|Lot Sizing|Safety Stock/i).first();
    if (await template.isVisible()) {
      await template.click();
      await page.waitForTimeout(500);
      // Should show parameters section
      await expect(page.getByText(/Parameter|Run Experiment/i).first()).toBeVisible({ timeout: 10_000 });
    }
  });
});

test.describe('Simulators', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('production simulator loads', async ({ page }) => {
    await navigateTo(page, '/simulator');
    await expectPageLoaded(page);
    await expect(page.getByText(/Simulator|Production|Session/i).first()).toBeVisible();
  });
});

test.describe('Product Costing', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('costing page loads', async ({ page }) => {
    await navigateTo(page, '/costing');
    await expectPageLoaded(page);
    await expect(page.getByText(/Costing|Cost Estimate|Variance/i).first()).toBeVisible();
  });

  test('costing - calculate cost button', async ({ page }) => {
    await navigateTo(page, '/costing');
    const calcBtn = page.locator('button:has-text("Calculate"), button:has-text("Cost"), button:has-text("Add")').first();
    if (await calcBtn.isVisible()) {
      await calcBtn.click();
      await page.waitForTimeout(500);
      await page.keyboard.press('Escape');
    }
  });
});

test.describe('Financial Operations', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('financial statements page loads', async ({ page }) => {
    await navigateTo(page, '/financial-statements');
    await expectPageLoaded(page);
    await expect(page.getByText(/Financial Statement|Balance Sheet|Income/i).first()).toBeVisible();
  });
});
