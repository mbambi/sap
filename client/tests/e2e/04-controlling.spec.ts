import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 4. CONTROLLING MODULE (CO)
// =====================================================================

test.describe('Controlling Module', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('cost centers page loads', async ({ page }) => {
    await navigateTo(page, '/controlling/cost-centers');
    await expectPageLoaded(page);
    await expect(page.getByText(/Cost Center/i).first()).toBeVisible();
  });

  test('cost centers - create form opens', async ({ page }) => {
    await navigateTo(page, '/controlling/cost-centers');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await expect(page.getByText(/Code|Name|Category/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });

  test('internal orders page loads', async ({ page }) => {
    await navigateTo(page, '/controlling/internal-orders');
    await expectPageLoaded(page);
    await expect(page.getByText(/Internal Order/i).first()).toBeVisible();
  });

  test('internal orders - create form opens', async ({ page }) => {
    await navigateTo(page, '/controlling/internal-orders');
    const addBtn = page.locator('button:has-text("Add New"), button:has-text("New"), button:has-text("Create")').first();
    if (await addBtn.isVisible()) {
      await addBtn.click();
      await expect(page.getByText(/Order|Description|Type|Budget/i).first()).toBeVisible();
      await page.keyboard.press('Escape');
    }
  });
});
