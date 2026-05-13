import { test as setup, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const STORAGE_STATE = path.join(__dirname, '.auth', 'student.json');

const STUDENT = {
  firstName: 'Test',
  lastName: 'Student',
  email: 'playwright@test.edu',
  password: 'password123',
};

setup('sign up and authenticate', async ({ page }) => {
  // Ensure .auth directory exists
  fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true });

  // Always do a fresh login to get a fresh JWT token
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded');

  // Wait for the org select to appear (means tenants API loaded)
  const orgSelect = page.locator('select').first();
  await orgSelect.waitFor({ state: 'visible', timeout: 30_000 });

  // Wait for real options to load (API may be slow on cold start)
  const firstOption = orgSelect.locator('option:not([value=""])').first();
  await firstOption.waitFor({ state: 'attached', timeout: 30_000 });
  const optionValue = await firstOption.getAttribute('value');
  if (optionValue) await orgSelect.selectOption(optionValue);

  // ─── Step 1: Try to Sign Up (may fail if user already exists) ───
  await page.getByText('New here? Create an account').click();
  await expect(page.getByRole('button', { name: /Create Account/i })).toBeVisible();

  await page.locator('input[type="text"]').first().fill(STUDENT.firstName);
  await page.locator('input[type="text"]').nth(1).fill(STUDENT.lastName);
  await page.locator('input[type="email"]').fill(STUDENT.email);
  await page.locator('input[type="password"]').fill(STUDENT.password);
  await page.getByRole('button', { name: /Create Account/i }).click();

  // Wait for result — either "Account created" or an error (user exists)
  const successOrError = page.getByText('Account created').or(page.locator('.bg-red-50'));
  await expect(successOrError).toBeVisible({ timeout: 15_000 });

  // ─── Step 2: Log In ───
  // After successful registration the form auto-switches to login mode.
  // If user already existed, we got an error and need to manually switch.
  const signInBtn = page.getByRole('button', { name: /Sign In/i });
  if (!(await signInBtn.isVisible({ timeout: 1_000 }).catch(() => false))) {
    // Still in register mode — switch to login
    await page.getByText('Already have an account? Sign in').click();
  }
  await expect(signInBtn).toBeVisible({ timeout: 5_000 });

  // Re-select org and fill credentials
  if (optionValue) await orgSelect.selectOption(optionValue);
  await page.locator('input[type="email"]').fill(STUDENT.email);
  await page.locator('input[type="password"]').fill(STUDENT.password);
  await signInBtn.click();

  // Wait for redirect to dashboard
  await page.waitForURL('/', { timeout: 20_000 });

  // Save signed-in state (localStorage with erp_token)
  await page.context().storageState({ path: STORAGE_STATE });
});
