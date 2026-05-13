import { expect, Page } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const STORAGE_STATE = path.join(__dirname, '..', '.auth', 'student.json');

const CREDS = {
  email: 'playwright@test.edu',
  password: 'password123',
};

/** Perform a full login on the /login page and persist the new token. */
export async function doLogin(page: Page) {
  const orgSelect = page.locator('select').first();
  await orgSelect.waitFor({ state: 'visible', timeout: 15_000 });

  // Wait for real options to load (API may be slow on cold start)
  const firstOption = orgSelect.locator('option:not([value=""])').first();
  await firstOption.waitFor({ state: 'attached', timeout: 30_000 });
  const optionValue = await firstOption.getAttribute('value');
  if (optionValue) await orgSelect.selectOption(optionValue);

  await page.locator('input[type="email"]').fill(CREDS.email);
  await page.locator('input[type="password"]').fill(CREDS.password);
  await page.getByRole('button', { name: /Sign In/i }).click();

  const sidebar = page.locator('aside').first();
  await page.waitForURL('/', { timeout: 20_000 });
  await expect(sidebar).toBeVisible({ timeout: 20_000 });

  // Persist the fresh token so subsequent tests skip re-login
  await page.context().storageState({ path: STORAGE_STATE });
}

/**
 * Wait for the app to settle after navigation.
 * Returns true if a re-login was needed.
 */
async function ensureLoggedIn(page: Page): Promise<boolean> {
  await page.waitForLoadState('domcontentloaded');

  const sidebar = page.locator('aside').first();
  const loginEmail = page.locator('input[type="email"]');
  await expect(sidebar.or(loginEmail)).toBeVisible({ timeout: 30_000 });

  if (await sidebar.isVisible()) return false;

  await doLogin(page);
  return true;
}

export async function loginAsStudent(page: Page) {
  await page.goto('/');
  await ensureLoggedIn(page);
}

export async function navigateTo(page: Page, targetPath: string) {
  await page.goto(targetPath);
  const reLoggedIn = await ensureLoggedIn(page);
  if (reLoggedIn) {
    // Re-login landed on '/' — navigate to the originally intended path
    await page.goto(targetPath);
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('aside').first()).toBeVisible({ timeout: 20_000 });
  }
}

export async function expectPageLoaded(page: Page) {
  const body = page.locator('body');
  await expect(body).not.toBeEmpty();
  const errorOverlay = page.locator('#webpack-dev-server-client-overlay, .vite-error-overlay');
  await expect(errorOverlay).toHaveCount(0);
}

export async function expectHeading(page: Page, text: string | RegExp) {
  await expect(page.getByRole('heading', { name: text }).first()).toBeVisible({ timeout: 10_000 });
}
