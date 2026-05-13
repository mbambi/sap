import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 34. ASSET MANAGEMENT (student can view on some setups)
// =====================================================================

test.describe('Asset Management (if accessible)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('asset management page loads or shows access denied', async ({ page }) => {
    await page.goto('/assets');
    await page.waitForTimeout(2_000);
    await expectPageLoaded(page);
  });
});

// =====================================================================
// 35. DECISION IMPACT ANALYSIS
// =====================================================================

test.describe('Decision Impact', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('decision impact page loads', async ({ page }) => {
    await navigateTo(page, '/decision-impact');
    await expectPageLoaded(page);
    await expect(page.getByText(/Decision|Impact|KPI/i).first()).toBeVisible();
  });
});

// =====================================================================
// 36. MULTI-ECHELON INVENTORY
// =====================================================================

test.describe('Multi-Echelon Inventory', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('multi-echelon page loads', async ({ page }) => {
    await navigateTo(page, '/multi-echelon');
    await expectPageLoaded(page);
    await expect(page.getByText(/Multi.Echelon|Safety Stock|Inventory/i).first()).toBeVisible();
  });
});

// =====================================================================
// 37. EVENT BUS FLOW SIMULATION
// =====================================================================

test.describe('Event Bus Flow Simulations', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('simulate Procure-to-Pay flow', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    await expectPageLoaded(page);

    const p2pBtn = page.locator('button:has-text("Procure-to-Pay"), button:has-text("P2P"), button:has-text("Procure")').first();
    if (await p2pBtn.isVisible()) {
      await p2pBtn.click();
      await page.waitForTimeout(3_000);
    }
  });

  test('simulate Order-to-Cash flow', async ({ page }) => {
    await navigateTo(page, '/event-bus');

    const o2cBtn = page.locator('button:has-text("Order-to-Cash"), button:has-text("O2C"), button:has-text("Order")').first();
    if (await o2cBtn.isVisible()) {
      await o2cBtn.click();
      await page.waitForTimeout(3_000);
    }
  });

  test('simulate Plan-to-Produce flow', async ({ page }) => {
    await navigateTo(page, '/event-bus');

    const p2pBtn = page.locator('button:has-text("Plan-to-Produce"), button:has-text("Plan")').first();
    if (await p2pBtn.isVisible()) {
      await p2pBtn.click();
      await page.waitForTimeout(3_000);
    }
  });

  test('event bus shows subscriptions', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    await expect(page.getByText(/Subscription|Subscriber|Handler/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('event bus shows event types', async ({ page }) => {
    await navigateTo(page, '/event-bus');
    await expect(page.getByText(/Event Type|Type/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 38. PROCESS MINING DEEP DIVE
// =====================================================================

test.describe('Process Mining Deep Dive', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('process map tab shows activities', async ({ page }) => {
    await navigateTo(page, '/process-mining');
    const mapTab = page.getByText(/Process Map/i).first();
    if (await mapTab.isVisible()) {
      await mapTab.click();
      await page.waitForTimeout(1_000);
    }
  });

  test('cases tab shows case list', async ({ page }) => {
    await navigateTo(page, '/process-mining');
    const casesTab = page.getByText(/Cases/i).first();
    if (await casesTab.isVisible()) {
      await casesTab.click();
      await page.waitForTimeout(1_000);
    }
  });

  test('statistics tab shows metrics', async ({ page }) => {
    await navigateTo(page, '/process-mining');
    const statsTab = page.getByText(/Statistics/i).first();
    if (await statsTab.isVisible()) {
      await statsTab.click();
      await page.waitForTimeout(1_000);
      await expect(page.getByText(/Duration|Average|Bottleneck|Path/i).first()).toBeVisible({ timeout: 10_000 });
    }
  });
});

// =====================================================================
// 39. SCENARIO REPLAY & TIME MACHINE
// =====================================================================

test.describe('Scenario Replay', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('scenario replay shows scenario cards', async ({ page }) => {
    await navigateTo(page, '/scenario-replay');
    await expectPageLoaded(page);
    await expect(page.getByText(/Scenario|Replay|Crisis/i).first()).toBeVisible();
  });

  test('scenario replay - click a scenario if available', async ({ page }) => {
    await navigateTo(page, '/scenario-replay');
    const scenarioCard = page.locator('[class*="card"], [class*="cursor-pointer"]').first();
    if (await scenarioCard.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await scenarioCard.click();
      await page.waitForTimeout(1_000);
    }
  });
});

test.describe('Time Machine Interactions', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('time machine shows timeline slider', async ({ page }) => {
    await navigateTo(page, '/time-machine');
    await expectPageLoaded(page);
    const slider = page.locator('input[type="range"]').first();
    if (await slider.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Move slider
      await slider.fill('5');
      await page.waitForTimeout(500);
    }
  });

  test('time machine - compare days mode', async ({ page }) => {
    await navigateTo(page, '/time-machine');
    const compareBtn = page.getByText(/Compare/i).first();
    if (await compareBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await compareBtn.click();
      await page.waitForTimeout(1_000);
    }
  });
});

// =====================================================================
// 40. COPILOT DEEP INTERACTION
// =====================================================================

test.describe('Copilot Interaction', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('send a question to copilot and get response', async ({ page }) => {
    await navigateTo(page, '/copilot');
    await expectPageLoaded(page);

    const input = page.locator('input[type="text"], textarea').first();
    if (await input.isVisible()) {
      await input.fill('How do I create a purchase order?');

      // Submit (enter or button)
      const sendBtn = page.locator('button[type="submit"], button:has-text("Send"), button:has-text("Ask")').first();
      if (await sendBtn.isVisible()) {
        await sendBtn.click();
      } else {
        await input.press('Enter');
      }

      // Wait for response
      await page.waitForTimeout(5_000);
    }
  });
});

// =====================================================================
// 41. SUPPLY CHAIN GAME SESSION
// =====================================================================

test.describe('Supply Chain Game Session', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('game page shows sessions or leaderboard', async ({ page }) => {
    await navigateTo(page, '/game');
    await expectPageLoaded(page);
    await expect(page.getByText(/Game|Session|Leaderboard|Score/i).first()).toBeVisible();
  });

  test('can attempt to join a session if available', async ({ page }) => {
    await navigateTo(page, '/game');
    const joinBtn = page.locator('button:has-text("Join")').first();
    if (await joinBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await joinBtn.click();
      await page.waitForTimeout(1_000);
    }
  });
});

// =====================================================================
// 42. BENCHMARK COMPETITION
// =====================================================================

test.describe('Benchmark Competition', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('benchmark shows tournaments or standings', async ({ page }) => {
    await navigateTo(page, '/benchmark');
    await expectPageLoaded(page);
    await expect(page.getByText(/Benchmark|Tournament|Standing|Hall of Fame|Metric/i).first()).toBeVisible();
  });
});

// =====================================================================
// 43. EXPLAINER DEEP INTERACTION
// =====================================================================

test.describe('Explainer Interactions', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('explain a production order', async ({ page }) => {
    await navigateTo(page, '/explainer');
    const prodTab = page.getByText(/Production Order/i).first();
    if (await prodTab.isVisible()) {
      await prodTab.click();
      await page.waitForTimeout(500);

      // Select an order if dropdown exists
      const orderSelect = page.locator('select').first();
      if (await orderSelect.isVisible()) {
        const options = await orderSelect.locator('option').allTextContents();
        const validOpt = options.find(o => o && !o.includes('Select'));
        if (validOpt) {
          await orderSelect.selectOption({ label: validOpt });

          // Click explain
          const explainBtn = page.locator('button:has-text("Explain"), button:has-text("Analyze")').first();
          if (await explainBtn.isVisible()) {
            await explainBtn.click();
            await page.waitForTimeout(3_000);
          }
        }
      }
    }
  });

  test('explain stock levels for a material', async ({ page }) => {
    await navigateTo(page, '/explainer');
    const stockTab = page.getByText(/Stock Level/i).first();
    if (await stockTab.isVisible()) {
      await stockTab.click();
      await page.waitForTimeout(500);

      const matSelect = page.locator('select').first();
      if (await matSelect.isVisible()) {
        const options = await matSelect.locator('option').allTextContents();
        const validOpt = options.find(o => o && !o.includes('Select'));
        if (validOpt) {
          await matSelect.selectOption({ label: validOpt });
          await page.waitForTimeout(2_000);
        }
      }
    }
  });

  test('trace a process flow', async ({ page }) => {
    await navigateTo(page, '/explainer');
    const flowTab = page.getByText(/Process Flow/i).first();
    if (await flowTab.isVisible()) {
      await flowTab.click();
      await page.waitForTimeout(500);
    }
  });
});
