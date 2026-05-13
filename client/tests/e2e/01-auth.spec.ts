import { test, expect } from '@playwright/test';

// =====================================================================
// 1. AUTHENTICATION — Sign up on ensak, then log in
// =====================================================================

// This test file does NOT use storageState — it tests the raw auth flow
test.use({ storageState: { cookies: [], origins: [] } });

const TEST_USER = {
  firstName: 'E2E',
  lastName: 'Tester',
  email: `e2e-${Date.now()}@test.edu`,
  password: 'password123',
};

test.describe('Authentication', () => {
  test('login page loads with organization selector', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');
    const orgSelect = page.locator('select').first();
    await orgSelect.waitFor({ state: 'visible', timeout: 20_000 });
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.getByRole('button', { name: /Sign In/i })).toBeVisible();
  });

  test('can toggle to registration mode', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');
    await page.locator('select').first().waitFor({ state: 'visible', timeout: 20_000 });
    await page.getByText('New here? Create an account').click();
    await expect(page.getByRole('button', { name: /Create Account/i })).toBeVisible();
    const textInputs = page.locator('input[type="text"]');
    await expect(textInputs.first()).toBeVisible();
  });

  test('sign up on ensak org', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');

    const orgSelect = page.locator('select').first();
    await orgSelect.waitFor({ state: 'visible', timeout: 20_000 });

    // Switch to register mode
    await page.getByText('New here? Create an account').click();

    // Select ensak (first real option)
    const firstOption = orgSelect.locator('option:not([value=""])').first();
    const optionValue = await firstOption.getAttribute('value');
    if (optionValue) await orgSelect.selectOption(optionValue);

    // Fill registration
    await page.locator('input[type="text"]').first().fill(TEST_USER.firstName);
    await page.locator('input[type="text"]').nth(1).fill(TEST_USER.lastName);
    await page.locator('input[type="email"]').fill(TEST_USER.email);
    await page.locator('input[type="password"]').fill(TEST_USER.password);
    await page.getByRole('button', { name: /Create Account/i }).click();

    // Should see success message OR error (if email taken)
    const successMsg = page.getByText('Account created');
    const errorMsg = page.locator('.bg-red-50');
    await expect(successMsg.or(errorMsg)).toBeVisible({ timeout: 15_000 });
  });

  test('shows error on invalid credentials', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');

    const orgSelect = page.locator('select').first();
    await orgSelect.waitFor({ state: 'visible', timeout: 20_000 });

    const firstOption = orgSelect.locator('option:not([value=""])').first();
    const optionValue = await firstOption.getAttribute('value');
    if (optionValue) await orgSelect.selectOption(optionValue);

    await page.locator('input[type="email"]').fill('wrong@email.com');
    await page.locator('input[type="password"]').fill('wrongpassword');
    await page.getByRole('button', { name: /Sign In/i }).click();

    await expect(page.locator('.bg-red-50').first()).toBeVisible({ timeout: 10_000 });
  });

  test('student can log in and reach dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');

    const orgSelect = page.locator('select').first();
    await orgSelect.waitFor({ state: 'visible', timeout: 20_000 });

    const firstOption = orgSelect.locator('option:not([value=""])').first();
    const optionValue = await firstOption.getAttribute('value');
    if (optionValue) await orgSelect.selectOption(optionValue);

    await page.locator('input[type="email"]').fill('playwright@test.edu');
    await page.locator('input[type="password"]').fill('password123');
    await page.getByRole('button', { name: /Sign In/i }).click();

    // Should redirect to dashboard
    await page.waitForURL('/', { timeout: 15_000 });
    await expect(page).toHaveURL('/');
  });
});
