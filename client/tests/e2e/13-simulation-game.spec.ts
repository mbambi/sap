import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 13. SIMULATION, GAME, BENCHMARK, STRESS TEST
// =====================================================================

test.describe('Supply Chain Game', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('game page loads', async ({ page }) => {
    await navigateTo(page, '/game');
    await expectPageLoaded(page);
    await expect(page.getByText(/Supply Chain Game|Compete/i).first()).toBeVisible();
  });

  test('game - session list or leaderboard visible', async ({ page }) => {
    await navigateTo(page, '/game');
    await expect(page.getByText(/Session|Leaderboard|Join|Score/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Multi-User Simulation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('simulation hub loads', async ({ page }) => {
    await navigateTo(page, '/simulation');
    await expectPageLoaded(page);
    await expect(page.getByText(/Simulation|Collaborative/i).first()).toBeVisible();
  });

  test('simulation - create or join section visible', async ({ page }) => {
    await navigateTo(page, '/simulation');
    await expect(page.getByText(/Create|Join|Session/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Benchmark Competition', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('benchmark page loads', async ({ page }) => {
    await navigateTo(page, '/benchmark');
    await expectPageLoaded(page);
    await expect(page.getByText(/Benchmark|Competition|Tournament/i).first()).toBeVisible();
  });
});

test.describe('Stress Test', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('stress test page loads', async ({ page }) => {
    await navigateTo(page, '/stress-test');
    await expectPageLoaded(page);
    await expect(page.getByText(/Stress Test|Crisis|Load/i).first()).toBeVisible();
  });
});

test.describe('Scenario Simulator', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('scenario simulator loads', async ({ page }) => {
    await navigateTo(page, '/scenarios/simulator');
    await expectPageLoaded(page);
    await expect(page.getByText(/Scenario|Simulator|Disruption/i).first()).toBeVisible();
  });

  test('scenario simulator - supplier delay section', async ({ page }) => {
    await navigateTo(page, '/scenarios/simulator');
    await expect(page.getByText(/Supplier Delay|Vendor/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('scenario simulator - demand spike section', async ({ page }) => {
    await navigateTo(page, '/scenarios/simulator');
    await expect(page.getByText(/Demand Spike|Multiplier/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Scenario Replay', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('scenario replay page loads', async ({ page }) => {
    await navigateTo(page, '/scenario-replay');
    await expectPageLoaded(page);
    await expect(page.getByText(/Scenario Replay|Time Travel|Crisis/i).first()).toBeVisible();
  });
});
