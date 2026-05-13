import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 11. ANALYTICS, BI, REPORTING, PROCESS MINING
// =====================================================================

test.describe('Analytics & BI', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('data warehouse loads', async ({ page }) => {
    await navigateTo(page, '/data-warehouse');
    await expectPageLoaded(page);
    await expect(page.getByText(/Data Warehouse|OLAP|ETL|Analytics/i).first()).toBeVisible();
  });

  test('data export lab loads', async ({ page }) => {
    await navigateTo(page, '/data-lab');
    await expectPageLoaded(page);
    await expect(page.getByText(/Data Export|Export Lab/i).first()).toBeVisible();
  });

  test('data export lab - entity list visible', async ({ page }) => {
    await navigateTo(page, '/data-lab');
    await expect(page.getByText(/CSV|JSON|Records/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('optimization engine loads', async ({ page }) => {
    await navigateTo(page, '/optimization');
    await expectPageLoaded(page);
    await expect(page.getByText(/Optimization|Engine/i).first()).toBeVisible();
  });

  test('decision impact loads', async ({ page }) => {
    await navigateTo(page, '/decision-impact');
    await expectPageLoaded(page);
    await expect(page.getByText(/Decision|Impact/i).first()).toBeVisible();
  });

  test('SQL explorer loads', async ({ page }) => {
    await navigateTo(page, '/sql-explorer');
    await expectPageLoaded(page);
    await expect(page.getByText(/SQL|Explorer|Query/i).first()).toBeVisible();
  });

  test('SQL explorer - can type query', async ({ page }) => {
    await navigateTo(page, '/sql-explorer');
    const textarea = page.locator('textarea, [contenteditable], input[type="text"]').first();
    if (await textarea.isVisible()) {
      await textarea.fill('SELECT * FROM Material LIMIT 10');
    }
  });

  test('role dashboard loads', async ({ page }) => {
    await navigateTo(page, '/role-dashboard');
    await expectPageLoaded(page);
    await expect(page.getByText(/Dashboard|Role/i).first()).toBeVisible();
  });
});

test.describe('Reporting', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('reporting dashboard loads', async ({ page }) => {
    await navigateTo(page, '/reporting');
    await expectPageLoaded(page);
    await expect(page.getByText(/Business Intelligence|Report/i).first()).toBeVisible();
  });

  test('reporting - KPI cards visible', async ({ page }) => {
    await navigateTo(page, '/reporting');
    await expect(page.getByText(/Revenue|Procurement|Open Items/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Process Mining', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('process mining page loads', async ({ page }) => {
    await navigateTo(page, '/process-mining');
    await expectPageLoaded(page);
    await expect(page.getByText(/Process Mining/i).first()).toBeVisible();
  });

  test('process mining - tabs work', async ({ page }) => {
    await navigateTo(page, '/process-mining');
    for (const tabName of ['Process Map', 'Cases', 'Statistics']) {
      const tab = page.getByText(new RegExp(tabName, 'i')).first();
      if (await tab.isVisible()) {
        await tab.click();
        await page.waitForTimeout(500);
      }
    }
  });
});
