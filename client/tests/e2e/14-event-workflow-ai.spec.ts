import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 14. EVENT BUS, WORKFLOW, COPILOT, EXPLAINER, TIME MACHINE
// =====================================================================

test.describe('Event Bus', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('event bus dashboard loads', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    await expectPageLoaded(page);
    await expect(page.getByText(/Event Bus|Event.Driven/i).first()).toBeVisible();
  });

  test('event bus - simulate flow buttons visible', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    await expect(page.getByText(/Procure.to.Pay|Order.to.Cash|Plan.to.Produce/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('event bus - click simulate P2P', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    const p2pBtn = page.locator('button:has-text("Procure"), button:has-text("P2P")').first();
    if (await p2pBtn.isVisible()) {
      await p2pBtn.click();
      await page.waitForTimeout(2_000);
    }
  });
});

test.describe('Workflow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('workflow page loads', async ({ page }) => {
    await navigateTo(page, '/workflow');
    await expectPageLoaded(page);
    await expect(page.getByText(/Workflow|Approval/i).first()).toBeVisible();
  });

  test('workflow - pending tasks section', async ({ page }) => {
    await navigateTo(page, '/workflow');
    await expect(page.getByText(/Pending|Task|Active/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('ERP Copilot', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('copilot page loads', async ({ page }) => {
    await navigateTo(page, '/copilot');
    await expectPageLoaded(page);
    await expect(page.getByText(/Copilot|AI|Assistant/i).first()).toBeVisible();
  });

  test('copilot - can type a question', async ({ page }) => {
    await navigateTo(page, '/copilot');
    const input = page.locator('input[type="text"], textarea').first();
    if (await input.isVisible()) {
      await input.fill('What is MRP?');
    }
  });
});

test.describe('AI Recommendations', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('recommendations page loads', async ({ page }) => {
    await navigateTo(page, '/recommendations');
    await expectPageLoaded(page);
    await expect(page.getByText(/Recommendation|AI|Decision/i).first()).toBeVisible();
  });

  test('recommendations - issues or suggestions visible', async ({ page }) => {
    await navigateTo(page, '/recommendations');
    await expect(page.getByText(/Critical|Warning|Issue|Category/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('ERP Explainer', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('explainer page loads', async ({ page }) => {
    await navigateTo(page, '/explainer');
    await expectPageLoaded(page);
    await expect(page.getByText(/Explainer|Decision|Why/i).first()).toBeVisible();
  });

  test('explainer - tabs work', async ({ page }) => {
    await navigateTo(page, '/explainer');
    for (const tabName of ['Production', 'Planned', 'Stock', 'Process']) {
      const tab = page.getByText(new RegExp(tabName, 'i')).first();
      if (await tab.isVisible()) {
        await tab.click();
        await page.waitForTimeout(500);
      }
    }
  });
});

test.describe('Time Machine', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('time machine page loads', async ({ page }) => {
    await navigateTo(page, '/time-machine');
    await expectPageLoaded(page);
    await expect(page.getByText(/Time Machine|Rewind|Replay/i).first()).toBeVisible();
  });

  test('time machine - timeline or events visible', async ({ page }) => {
    await navigateTo(page, '/time-machine');
    await expect(page.getByText(/Day|Event|Timeline/i).first()).toBeVisible({ timeout: 10_000 });
  });
});
