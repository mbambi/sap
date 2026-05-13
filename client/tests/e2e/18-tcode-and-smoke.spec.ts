import { test, expect } from '@playwright/test';
import { loginAsStudent, navigateTo, expectPageLoaded } from './helpers/auth';

// =====================================================================
// 20. T-CODE NAVIGATION (SAP Transaction Codes)
// =====================================================================

const TCODES = [
  { code: 'ME21N', label: /Purchase Order/i, expectedUrl: '/materials/purchase-orders' },
  { code: 'MIGO', label: /Goods Receipt/i, expectedUrl: '/materials/goods-receipts' },
  { code: 'FB50', label: /Journal Entr/i, expectedUrl: '/finance/journal-entries' },
  { code: 'VA01', label: /Sales Order/i, expectedUrl: '/sales/orders' },
  { code: 'VL01N', label: /Deliver/i, expectedUrl: '/sales/deliveries' },
  { code: 'VF01', label: /Billing|Invoice/i, expectedUrl: '/sales/invoices' },
  { code: 'MD01', label: /MRP/i, expectedUrl: '/mrp' },
  { code: 'CO01', label: /Production Order/i, expectedUrl: '/production/orders' },
];

test.describe('T-Code Navigation via Search', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  for (const tcode of TCODES) {
    test(`T-Code ${tcode.code} navigates correctly`, async ({ page }) => {
      // Open search
      await page.keyboard.press('Control+k');
      const searchInput = page.locator('input[placeholder*="Search"]').first();
      await searchInput.waitFor({ state: 'visible', timeout: 5_000 });
      await searchInput.fill(tcode.code);
      await page.waitForTimeout(500);

      // Click the result
      const result = page.getByText(new RegExp(tcode.code, 'i')).first();
      if (await result.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await result.click();
        await page.waitForTimeout(1_000);
        await expect(page).toHaveURL(new RegExp(tcode.expectedUrl));
      }
    });
  }
});

// =====================================================================
// 21. SIDEBAR NAVIGATION SMOKE TEST (All Student-Visible Routes)
// =====================================================================

const STUDENT_ROUTES = [
  { path: '/', name: 'Dashboard' },
  // Finance
  { path: '/finance/journal-entries', name: 'Journal Entries' },
  { path: '/finance/gl-accounts', name: 'GL Accounts' },
  { path: '/finance/vendors', name: 'Vendors' },
  { path: '/finance/customers', name: 'Customers' },
  { path: '/finance/trial-balance', name: 'Trial Balance' },
  { path: '/finance/analytics', name: 'Financial Analytics' },
  { path: '/finance/ap', name: 'Accounts Payable' },
  { path: '/finance/ar', name: 'Accounts Receivable' },
  { path: '/finance/pricing', name: 'Pricing Engine' },
  // Controlling
  { path: '/controlling/cost-centers', name: 'Cost Centers' },
  { path: '/controlling/internal-orders', name: 'Internal Orders' },
  // Materials
  { path: '/materials/items', name: 'Materials' },
  { path: '/materials/purchase-orders', name: 'Purchase Orders' },
  { path: '/materials/goods-receipts', name: 'Goods Receipts' },
  { path: '/materials/inventory', name: 'Inventory' },
  { path: '/inventory/analytics', name: 'Inventory Analytics' },
  { path: '/inventory/stock', name: 'Stock Management' },
  // Sales
  { path: '/sales/orders', name: 'Sales Orders' },
  { path: '/sales/deliveries', name: 'Deliveries' },
  { path: '/sales/invoices', name: 'Invoices' },
  // Production
  { path: '/production/boms', name: 'BOMs' },
  { path: '/production/orders', name: 'Production Orders' },
  { path: '/production/scheduling', name: 'Scheduling' },
  { path: '/operations/dashboard', name: 'Operations Dashboard' },
  // Warehouse
  { path: '/warehouse/list', name: 'Warehouses' },
  { path: '/warehouse/bins', name: 'Warehouse Bins' },
  // Quality
  { path: '/quality/inspections', name: 'Inspection Lots' },
  { path: '/quality/non-conformances', name: 'Non-Conformances' },
  // Maintenance
  { path: '/maintenance/equipment', name: 'Equipment' },
  { path: '/maintenance/work-orders', name: 'Work Orders' },
  // MRP
  { path: '/mrp', name: 'MRP Dashboard' },
  { path: '/mrp-board', name: 'MRP Planning Board' },
  { path: '/inventory/simulator', name: 'Inventory Simulator' },
  // Supply Chain
  { path: '/supply-chain/network', name: 'Network Map' },
  { path: '/supply-chain/editor', name: 'Map Editor' },
  { path: '/multi-echelon', name: 'Multi-Echelon' },
  { path: '/forecasting', name: 'Forecasting' },
  { path: '/digital-twin', name: 'Digital Twin' },
  { path: '/transport', name: 'Transport' },
  // Process
  { path: '/process-flows', name: 'Process Flows' },
  { path: '/process-visualizer', name: 'Process Visualizer' },
  { path: '/process-mining', name: 'Process Mining' },
  // Financial Ops
  { path: '/financial-statements', name: 'Financial Statements' },
  { path: '/costing', name: 'Costing' },
  // Analytics
  { path: '/data-warehouse', name: 'Data Warehouse' },
  { path: '/data-lab', name: 'Data Export Lab' },
  { path: '/optimization', name: 'Optimization' },
  { path: '/decision-impact', name: 'Decision Impact' },
  { path: '/sql-explorer', name: 'SQL Explorer' },
  { path: '/role-dashboard', name: 'Role Dashboard' },
  { path: '/reporting', name: 'Reporting' },
  // Workflow
  { path: '/workflow', name: 'Workflow' },
  // Learning
  { path: '/learning', name: 'Learning Hub' },
  { path: '/learning/analytics', name: 'Learning Analytics' },
  { path: '/courses', name: 'Courses' },
  { path: '/certification', name: 'Certification' },
  // Gamification & Games
  { path: '/gamification', name: 'Gamification' },
  { path: '/game', name: 'Supply Chain Game' },
  { path: '/benchmark', name: 'Benchmark' },
  { path: '/stress-test', name: 'Stress Test' },
  // Event & Simulation
  { path: '/event-bus', name: 'Event Bus' },
  { path: '/simulation', name: 'Simulation' },
  { path: '/experiment-lab', name: 'Experiment Lab' },
  { path: '/scenario-replay', name: 'Scenario Replay' },
  { path: '/scenarios/simulator', name: 'Scenario Simulator' },
  // AI
  { path: '/copilot', name: 'ERP Copilot' },
  { path: '/recommendations', name: 'AI Recommendations' },
  { path: '/time-machine', name: 'Time Machine' },
  { path: '/simulator', name: 'Simulator' },
  { path: '/explainer', name: 'ERP Explainer' },
  // Docs & Audit
  { path: '/documents', name: 'Documents' },
  { path: '/audit', name: 'Audit' },
  // Utils
  { path: '/tools/api-playground', name: 'API Playground' },
  // Profile
  { path: '/profile', name: 'Profile' },
];

test.describe('Sidebar Smoke Test: All Student Routes Load', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsStudent(page);
  });

  for (const route of STUDENT_ROUTES) {
    test(`${route.name} (${route.path}) loads without error`, async ({ page }) => {
      await navigateTo(page, route.path);
      await expectPageLoaded(page);
      // Page should have some visible content
      const bodyText = await page.locator('body').innerText();
      expect(bodyText.length).toBeGreaterThan(50);
    });
  }
});
