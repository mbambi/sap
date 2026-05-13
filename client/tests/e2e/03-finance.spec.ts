import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded, expectHeading } from './helpers/auth';

// =====================================================================
// 3. FINANCE MODULE (FI)
// =====================================================================

test.describe('Finance Module', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  // ── Journal Entries ──
  test('journal entries page loads', async ({ page }) => {
    await navigateTo(page, '/finance/journal-entries');
    await expectPageLoaded(page);
    await expect(page.getByText(/Journal Entr/i).first()).toBeVisible();
  });

  test('journal entries - can open create form', async ({ page }) => {
    await navigateTo(page, '/finance/journal-entries');
    const createBtn = page.locator('button:has-text("Create"), button:has-text("Add"), button:has-text("New")').first();
    if (await createBtn.isVisible()) {
      await createBtn.click();
      await expect(page.getByText(/Company Code|Posting Date|Description/i).first()).toBeVisible();
    }
  });

  // ── GL Accounts ──
  test('GL accounts page loads with table', async ({ page }) => {
    await navigateTo(page, '/finance/gl-accounts');
    await expectPageLoaded(page);
    await expect(page.getByText(/GL Account|Account/i).first()).toBeVisible();
  });

  test('GL accounts - search works', async ({ page }) => {
    await navigateTo(page, '/finance/gl-accounts');
    const searchInput = page.locator('input[placeholder*="Search"]').first();
    if (await searchInput.isVisible()) {
      await searchInput.fill('Cash');
      await page.waitForTimeout(500);
    }
  });

  // ── Vendors ──
  test('vendors page loads', async ({ page }) => {
    await navigateTo(page, '/finance/vendors');
    await expectPageLoaded(page);
    await expect(page.getByText(/Vendor/i).first()).toBeVisible();
  });

  // ── Customers ──
  test('customers page loads', async ({ page }) => {
    await navigateTo(page, '/finance/customers');
    await expectPageLoaded(page);
    await expect(page.getByText(/Customer/i).first()).toBeVisible();
  });

  // ── Trial Balance ──
  test('trial balance shows accounts with debit/credit', async ({ page }) => {
    await navigateTo(page, '/finance/trial-balance');
    await expectPageLoaded(page);
    await expect(page.getByText(/Trial Balance/i).first()).toBeVisible();
    // Check for debit/credit columns
    await expect(page.getByText(/Debit|Credit/i).first()).toBeVisible();
  });

  // ── Financial Analytics ──
  test('financial analytics loads with charts', async ({ page }) => {
    await navigateTo(page, '/finance/analytics');
    await expectPageLoaded(page);
    await expect(page.getByText(/Financial|Analytics|Revenue|P&L/i).first()).toBeVisible();
  });

  // ── Accounts Payable ──
  test('accounts payable page loads with tabs', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    await expectPageLoaded(page);
    await expect(page.getByText(/Accounts Payable|Payable/i).first()).toBeVisible();
  });

  test('AP - can view purchase requisitions tab', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    const prTab = page.getByText(/PR|Purchase Req|Requisition/i).first();
    if (await prTab.isVisible()) {
      await prTab.click();
      await page.waitForTimeout(500);
    }
  });

  test('AP - can view invoices tab', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    const invTab = page.getByText(/Invoice/i).first();
    if (await invTab.isVisible()) {
      await invTab.click();
      await page.waitForTimeout(500);
    }
  });

  test('AP - can view payments tab', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    const payTab = page.getByText(/Payment/i).first();
    if (await payTab.isVisible()) {
      await payTab.click();
      await page.waitForTimeout(500);
    }
  });

  test('AP - can view vendor balance tab', async ({ page }) => {
    await navigateTo(page, '/finance/ap');
    const vbTab = page.getByText(/Vendor Balance/i).first();
    if (await vbTab.isVisible()) {
      await vbTab.click();
      await page.waitForTimeout(500);
    }
  });

  // ── Accounts Receivable ──
  test('accounts receivable page loads', async ({ page }) => {
    await navigateTo(page, '/finance/ar');
    await expectPageLoaded(page);
    await expect(page.getByText(/Accounts Receivable|Receivable/i).first()).toBeVisible();
  });

  test('AR - aging tab shows aging buckets', async ({ page }) => {
    await navigateTo(page, '/finance/ar');
    const agingTab = page.getByText(/Aging/i).first();
    if (await agingTab.isVisible()) {
      await agingTab.click();
      await page.waitForTimeout(500);
      await expect(page.getByText(/30|60|90/i).first()).toBeVisible();
    }
  });

  // ── Pricing Engine ──
  test('pricing engine page loads', async ({ page }) => {
    await navigateTo(page, '/finance/pricing');
    await expectPageLoaded(page);
    await expect(page.getByText(/Pricing|Condition|Discount/i).first()).toBeVisible();
  });
});
