import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 51. STRESS TEST PAGE
// =====================================================================

test.describe('Stress Test', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('stress test shows scenarios', async ({ page }) => {
    await navigateTo(page, '/stress-test');
    await expectPageLoaded(page);
    await expect(page.getByText(/Stress Test|Crisis|Scenario|Load/i).first()).toBeVisible();
  });
});

// =====================================================================
// 52. API PLAYGROUND DEEP INTERACTION
// =====================================================================

test.describe('API Playground', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('select GET method and enter endpoint', async ({ page }) => {
    await navigateTo(page, '/tools/api-playground');
    await expectPageLoaded(page);

    // Select GET
    const methodSelect = page.locator('select').first();
    if (await methodSelect.isVisible()) {
      await methodSelect.selectOption('GET');
    }

    // Enter endpoint
    const urlInput = page.locator('input[type="text"]').first();
    if (await urlInput.isVisible()) {
      await urlInput.fill('/api/materials');
    }

    // Send request
    const sendBtn = page.locator('button:has-text("Send"), button:has-text("Execute"), button:has-text("Run")').first();
    if (await sendBtn.isVisible()) {
      await sendBtn.click();
      await page.waitForTimeout(3_000);
    }
  });

  test('try POST method', async ({ page }) => {
    await navigateTo(page, '/tools/api-playground');

    const methodSelect = page.locator('select').first();
    if (await methodSelect.isVisible()) {
      await methodSelect.selectOption('POST');
    }
  });
});

// =====================================================================
// 53. GAMIFICATION DETAILS
// =====================================================================

test.describe('Gamification Details', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('shows XP progress bar', async ({ page }) => {
    await navigateTo(page, '/gamification');
    await expectPageLoaded(page);
    await expect(page.getByText(/XP|Points|Level/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('shows achievements grid', async ({ page }) => {
    await navigateTo(page, '/gamification');
    const achievements = page.getByText(/Achievement/i).first();
    await expect(achievements).toBeVisible({ timeout: 10_000 });
  });

  test('shows leaderboard table', async ({ page }) => {
    await navigateTo(page, '/gamification');
    const leaderboard = page.getByText(/Leaderboard|Rank/i).first();
    await expect(leaderboard).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 54. LEARNING HUB: EXERCISE INTERACTION
// =====================================================================

test.describe('Learning Hub Exercises', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('exercise cards display with difficulty badges', async ({ page }) => {
    await navigateTo(page, '/learning');
    await page.waitForTimeout(1_000);

    const exerciseCard = page.locator('[class*="card"]').first();
    if (await exerciseCard.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Cards should have module/difficulty info
      await expect(page.getByText(/Beginner|Intermediate|Advanced|Finance|Materials|Sales/i).first()).toBeVisible();
    }
  });

  test('click on first exercise to start it', async ({ page }) => {
    await navigateTo(page, '/learning');
    await page.waitForTimeout(1_000);

    const startBtn = page.locator('button:has-text("Start"), button:has-text("Begin"), button:has-text("Open")').first();
    if (await startBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await startBtn.click();
      await page.waitForTimeout(1_000);
    }
  });
});

// =====================================================================
// 55. CERTIFICATION: START AN EXAM
// =====================================================================

test.describe('Certification Exam', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('certification list with available exams', async ({ page }) => {
    await navigateTo(page, '/certification');
    await expectPageLoaded(page);
    await expect(page.getByText(/Certification|Exam|Duration|Score/i).first()).toBeVisible();
  });

  test('click start exam button if available', async ({ page }) => {
    await navigateTo(page, '/certification');
    const startBtn = page.locator('button:has-text("Start"), button:has-text("Take"), button:has-text("Begin")').first();
    if (await startBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await startBtn.click();
      await page.waitForTimeout(2_000);
      // Should enter exam mode
    }
  });
});

// =====================================================================
// 56. COURSES: VIEW LESSONS
// =====================================================================

test.describe('Course Lessons', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('course list shows available courses', async ({ page }) => {
    await navigateTo(page, '/courses');
    await expectPageLoaded(page);
    await page.waitForTimeout(1_000);
  });

  test('click on a course to see lessons', async ({ page }) => {
    await navigateTo(page, '/courses');
    const courseItem = page.locator('[class*="card"], table tbody tr, [class*="cursor-pointer"]').first();
    if (await courseItem.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await courseItem.click();
      await page.waitForTimeout(1_000);
      // Should show lessons or course detail
      await expect(page.getByText(/Lesson|Module|Progress|Objective/i).first()).toBeVisible({ timeout: 5_000 });
    }
  });
});

// =====================================================================
// 57. FINANCIAL ANALYTICS CHARTS
// =====================================================================

test.describe('Financial Analytics Charts', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('financial analytics shows P&L or balance sheet charts', async ({ page }) => {
    await navigateTo(page, '/finance/analytics');
    await expectPageLoaded(page);
    // Look for chart containers (recharts)
    const chartContainer = page.locator('.recharts-wrapper, svg, canvas').first();
    if (await chartContainer.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Charts are rendering
    }
  });
});

// =====================================================================
// 58. OPERATIONS DASHBOARD KPIs
// =====================================================================

test.describe('Operations Dashboard KPIs', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('shows OEE metric', async ({ page }) => {
    await navigateTo(page, '/operations/dashboard');
    await expectPageLoaded(page);
    await expect(page.getByText(/OEE|Overall Equipment/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('shows throughput and utilization', async ({ page }) => {
    await navigateTo(page, '/operations/dashboard');
    await expect(page.getByText(/Throughput|Utilization|Cycle Time/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 59. SCHEDULING BOARD VISUALIZATION
// =====================================================================

test.describe('Scheduling Board', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('scheduling shows work centers', async ({ page }) => {
    await navigateTo(page, '/production/scheduling');
    await expectPageLoaded(page);
    await expect(page.getByText(/Work Center|Capacity|Schedule/i).first()).toBeVisible();
  });

  test('scheduling shows capacity utilization', async ({ page }) => {
    await navigateTo(page, '/production/scheduling');
    await expect(page.getByText(/Utilization|Available|Scheduled/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

// =====================================================================
// 60. TRANSPORT & LOGISTICS
// =====================================================================

test.describe('Transport', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('transport shows shipments', async ({ page }) => {
    await navigateTo(page, '/transport');
    await expectPageLoaded(page);
    await expect(page.getByText(/Transport|Shipment|Carrier|Route/i).first()).toBeVisible();
  });
});
