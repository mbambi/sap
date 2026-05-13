import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 10. SUPPLY CHAIN, DIGITAL TWIN, TRANSPORT
// =====================================================================

test.describe('Supply Chain', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('network map loads', async ({ page }) => {
    await navigateTo(page, '/supply-chain/network');
    await expectPageLoaded(page);
    await expect(page.getByText(/Supply Chain|Network/i).first()).toBeVisible();
  });

  test('map editor loads', async ({ page }) => {
    await navigateTo(page, '/supply-chain/editor');
    await expectPageLoaded(page);
    await expect(page.getByText(/Supply Chain|Editor|Network/i).first()).toBeVisible();
  });

  test('multi-echelon page loads', async ({ page }) => {
    await navigateTo(page, '/multi-echelon');
    await expectPageLoaded(page);
    await expect(page.getByText(/Multi.Echelon|Inventory|Safety Stock/i).first()).toBeVisible();
  });

  test('forecasting page loads', async ({ page }) => {
    await navigateTo(page, '/forecasting');
    await expectPageLoaded(page);
    await expect(page.getByText(/Forecast|Demand|Model/i).first()).toBeVisible();
  });

  test('digital twin loads', async ({ page }) => {
    await navigateTo(page, '/digital-twin');
    await expectPageLoaded(page);
    await expect(page.getByText(/Digital Twin|Supply Chain/i).first()).toBeVisible();
  });

  test('transport page loads', async ({ page }) => {
    await navigateTo(page, '/transport');
    await expectPageLoaded(page);
    await expect(page.getByText(/Transport|Shipment|Logistics/i).first()).toBeVisible();
  });
});

test.describe('Process Flows & Visualization', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('process flows page loads', async ({ page }) => {
    await navigateTo(page, '/process-flows');
    await expectPageLoaded(page);
    await expect(page.getByText(/Process Flow|P2P|O2C/i).first()).toBeVisible();
  });

  test('process visualizer loads with interactive nodes', async ({ page }) => {
    await navigateTo(page, '/process-visualizer');
    await expectPageLoaded(page);
    await expect(page.getByText(/Process|Visualizer|Procure|Order/i).first()).toBeVisible();
  });

  test('process visualizer - click P2P flow', async ({ page }) => {
    await navigateTo(page, '/process-visualizer');
    const p2pBtn = page.getByText(/Procure.to.Pay/i).first();
    if (await p2pBtn.isVisible()) {
      await p2pBtn.click();
      await page.waitForTimeout(500);
    }
  });

  test('process visualizer - click O2C flow', async ({ page }) => {
    await navigateTo(page, '/process-visualizer');
    const o2cBtn = page.getByText(/Order.to.Cash/i).first();
    if (await o2cBtn.isVisible()) {
      await o2cBtn.click();
      await page.waitForTimeout(500);
    }
  });
});
