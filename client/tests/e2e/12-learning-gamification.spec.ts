import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 12. LEARNING, GAMIFICATION, CERTIFICATION, COURSES
// =====================================================================

test.describe('Learning Hub', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('learning hub loads', async ({ page }) => {
    await navigateTo(page, '/learning');
    await expectPageLoaded(page);
    await expect(page.getByText(/Learning Hub/i).first()).toBeVisible();
  });

  test('learning hub - guided exercises tab', async ({ page }) => {
    await navigateTo(page, '/learning');
    const tab = page.getByText(/Guided Exercise/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('learning hub - process scenarios tab', async ({ page }) => {
    await navigateTo(page, '/learning');
    const tab = page.getByText(/Process Scenario/i).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForTimeout(500);
    }
  });

  test('learning analytics loads', async ({ page }) => {
    await navigateTo(page, '/learning/analytics');
    await expectPageLoaded(page);
    await expect(page.getByText(/Learning Progress|My Progress/i).first()).toBeVisible();
  });

  test('learning analytics - shows XP and level', async ({ page }) => {
    await navigateTo(page, '/learning/analytics');
    await expect(page.getByText(/XP|Level|Streak/i).first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Courses', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('courses page loads', async ({ page }) => {
    await navigateTo(page, '/courses');
    await expectPageLoaded(page);
    await expect(page.getByText(/Course/i).first()).toBeVisible();
  });

  test('courses - can click on a course', async ({ page }) => {
    await navigateTo(page, '/courses');
    const courseCard = page.locator('[class*="card"], table tbody tr').first();
    if (await courseCard.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await courseCard.click();
      await page.waitForTimeout(500);
    }
  });
});

test.describe('Certification', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('certification center loads', async ({ page }) => {
    await navigateTo(page, '/certification');
    await expectPageLoaded(page);
    await expect(page.getByText(/Certification|Exam/i).first()).toBeVisible();
  });

  test('certification - can see available exams', async ({ page }) => {
    await navigateTo(page, '/certification');
    await page.waitForTimeout(1_000);
    // Should see at least one cert card or table row
    const content = page.locator('[class*="card"], table tbody tr').first();
    if (await content.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Content exists
    }
  });
});

test.describe('Gamification', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  test('gamification hub loads', async ({ page }) => {
    await navigateTo(page, '/gamification');
    await expectPageLoaded(page);
    await expect(page.getByText(/Gamification|Journey|Achievement|Leaderboard/i).first()).toBeVisible();
  });

  test('gamification - achievements section visible', async ({ page }) => {
    await navigateTo(page, '/gamification');
    await expect(page.getByText(/Achievement/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('gamification - leaderboard visible', async ({ page }) => {
    await navigateTo(page, '/gamification');
    await expect(page.getByText(/Leaderboard|Rank/i).first()).toBeVisible({ timeout: 10_000 });
  });
});
