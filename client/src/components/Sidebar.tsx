import { NavLink, useLocation } from "react-router-dom";
import {
  LayoutDashboard,
  Landmark,
  Package,
  ShoppingCart,
  Factory,
  Warehouse as WarehouseIcon,
  ClipboardCheck,
  Wrench,
  Users,
  PieChart,
  GitBranch,
  Activity,
  Settings,
  GraduationCap,
  ChevronDown,
  ChevronRight,
  Calculator,
  X,
  Trophy,
  Terminal,
  Bot,
  Network,
  Boxes,
  BarChart3,
  Clock,
  Gamepad2,
  HelpCircle,
  DollarSign,
  Laptop,
  Truck,
  Shield,
  Building2,
  FileSpreadsheet,
  CalendarCheck,
  Globe,
  Database,
  Cpu,
  Link2,
  FileText,
  BookOpen,
  Award,
  Gauge,
  Layers,
  Workflow,
  Radar,
  FlaskConical,
  Scale,
  Zap,
  Search,
  Eye,
  Sparkles,
  Map,
  Film,
  Brain,
  LineChart,
  Milestone,
} from "lucide-react";
import { useState, useMemo } from "react";
import { useAuthStore } from "../stores/auth";

type RoleSet = ("admin" | "instructor" | "student" | "auditor")[];

interface NavItem {
  id: string;
  label: string;
  icon?: React.ElementType;
  path?: string;
  roles?: RoleSet;
  children?: NavItem[];
}

const ALL_ROLES: RoleSet = ["admin", "instructor", "student", "auditor"];
const STAFF: RoleSet = ["admin", "instructor"];
const ADMIN_ONLY: RoleSet = ["admin"];

const navItems: NavItem[] = [
  { id: "dashboard", label: "Dashboard", icon: LayoutDashboard, path: "/" },
  {
    id: "logistics",
    label: "Logistics",
    icon: Truck,
    children: [
      {
        id: "materials",
        label: "Materials (MM)",
        icon: Package,
        children: [
          { id: "materials-items", label: "Materials", path: "/materials/items" },
          { id: "materials-pos", label: "Purchase Orders", path: "/materials/purchase-orders" },
          { id: "materials-gr", label: "Goods Receipts", path: "/materials/goods-receipts" },
          { id: "materials-inv", label: "Inventory", path: "/materials/inventory" },
          { id: "materials-plants", label: "Plants", path: "/materials/plants", roles: STAFF },
          { id: "inventory-analytics", label: "Inventory Analytics", path: "/inventory/analytics" },
          { id: "inventory-stock", label: "Stock Management", path: "/inventory/stock" },
        ],
      },
      {
        id: "sales",
        label: "Sales (SD)",
        icon: ShoppingCart,
        children: [
          { id: "sales-orders", label: "Sales Orders", path: "/sales/orders" },
          { id: "sales-deliveries", label: "Deliveries", path: "/sales/deliveries" },
          { id: "sales-invoices", label: "Invoices", path: "/sales/invoices" },
        ],
      },
      {
        id: "production",
        label: "Production (PP)",
        icon: Factory,
        children: [
          { id: "production-boms", label: "BOMs", path: "/production/boms" },
          { id: "production-orders", label: "Production Orders", path: "/production/orders" },
          { id: "production-scheduling", label: "Scheduling", path: "/production/scheduling" },
          { id: "operations-dashboard", label: "Operations Dashboard", path: "/operations/dashboard" },
        ],
      },
      {
        id: "warehouse",
        label: "Warehouse (WM)",
        icon: WarehouseIcon,
        children: [
          { id: "warehouse-list", label: "Warehouses", path: "/warehouse/list" },
          { id: "warehouse-bins", label: "Bin Management", path: "/warehouse/bins" },
        ],
      },
      {
        id: "quality",
        label: "Quality (QM)",
        icon: ClipboardCheck,
        children: [
          { id: "quality-inspections", label: "Inspection Lots", path: "/quality/inspections" },
          { id: "quality-nc", label: "Non-Conformances", path: "/quality/non-conformances" },
        ],
      },
      {
        id: "maintenance",
        label: "Maintenance (PM)",
        icon: Wrench,
        children: [
          { id: "maintenance-equipment", label: "Equipment", path: "/maintenance/equipment" },
          { id: "maintenance-work-orders", label: "Work Orders", path: "/maintenance/work-orders" },
        ],
      },
      {
        id: "mrp",
        label: "MRP & Planning",
        icon: Boxes,
        children: [
          { id: "mrp-dashboard", label: "MRP Dashboard", path: "/mrp" },
          { id: "mrp-board", label: "MRP Planning Board", path: "/mrp-board" },
          { id: "inventory-simulator", label: "Inventory Simulator", path: "/inventory/simulator" },
        ],
      },
      {
        id: "supply-chain",
        label: "Supply Chain",
        icon: Network,
        children: [
          { id: "supply-chain-network", label: "Network Map", path: "/supply-chain/network" },
          { id: "supply-chain-editor", label: "Map Editor", path: "/supply-chain/editor" },
          { id: "multi-echelon", label: "Multi-Echelon", path: "/multi-echelon" },
          { id: "forecasting", label: "Forecasting", path: "/forecasting" },
        ],
      },
      { id: "transport", label: "Transport", icon: Truck, path: "/transport" },
      { id: "process-flows", label: "Process Flows", icon: Workflow, path: "/process-flows" },
      { id: "process-visualizer", label: "Process Visualizer", icon: Sparkles, path: "/process-visualizer" },
      { id: "digital-twin", label: "Digital Twin", icon: Radar, path: "/digital-twin" },
    ],
  },
  {
    id: "finance",
    label: "Finance & Controlling",
    icon: Landmark,
    children: [
      {
        id: "finance-fi",
        label: "Finance (FI)",
        icon: Landmark,
        children: [
          { id: "finance-journal", label: "Journal Entries", path: "/finance/journal-entries" },
          { id: "finance-gl", label: "GL Accounts", path: "/finance/gl-accounts" },
          { id: "finance-companies", label: "Company Codes", path: "/finance/company-codes", roles: STAFF },
          { id: "finance-vendors", label: "Vendors", path: "/finance/vendors" },
          { id: "finance-customers", label: "Customers", path: "/finance/customers" },
          { id: "finance-trial", label: "Trial Balance", path: "/finance/trial-balance" },
          { id: "finance-analytics", label: "Financial Analytics", path: "/finance/analytics" },
          { id: "finance-ap", label: "Accounts Payable", path: "/finance/ap" },
          { id: "finance-ar", label: "Accounts Receivable", path: "/finance/ar" },
          { id: "finance-pricing", label: "Pricing Engine", path: "/finance/pricing" },
        ],
      },
      {
        id: "controlling",
        label: "Controlling (CO)",
        icon: Calculator,
        children: [
          { id: "controlling-cost-centers", label: "Cost Centers", path: "/controlling/cost-centers" },
          { id: "controlling-internal-orders", label: "Internal Orders", path: "/controlling/internal-orders" },
        ],
      },
      {
        id: "finance-ops",
        label: "Finance Ops",
        icon: FileSpreadsheet,
        children: [
          { id: "financial-statements", label: "Financial Statements", path: "/financial-statements" },
          { id: "period-closing", label: "Period Closing", path: "/period-closing", roles: STAFF },
        ],
      },
      { id: "costing", label: "Costing", icon: DollarSign, path: "/costing" },
      { id: "assets", label: "Asset Management", icon: Laptop, path: "/assets", roles: STAFF },
      { id: "multi-company", label: "Multi-Company", icon: Building2, path: "/multi-company", roles: STAFF },
    ],
  },
  {
    id: "people-learning",
    label: "People & Learning",
    icon: Users,
    children: [
      {
        id: "hr",
        label: "HR",
        icon: Users,
        roles: STAFF,
        children: [
          { id: "hr-employees", label: "Employees", path: "/hr/employees" },
          { id: "hr-org-units", label: "Org Structure", path: "/hr/org-units" },
          { id: "hr-leave-requests", label: "Leave Requests", path: "/hr/leave-requests" },
          { id: "hr-time-entries", label: "Time Entries", path: "/hr/time-entries" },
        ],
      },
      {
        id: "learning",
        label: "Learning",
        icon: GraduationCap,
        children: [
          { id: "learning-hub", label: "Learning Hub", path: "/learning" },
          { id: "learning-progress", label: "My Progress", path: "/learning/analytics" },
          { id: "learning-courses", label: "Courses", path: "/courses" },
          { id: "learning-certification", label: "Certification", path: "/certification" },
        ],
      },
      { id: "gamification", label: "Gamification", icon: Trophy, path: "/gamification" },
      { id: "supply-chain-game", label: "Supply Chain Game", icon: Gamepad2, path: "/game" },
    ],
  },
  {
    id: "analytics",
    label: "Analytics & Planning",
    icon: BarChart3,
    children: [
      { id: "reporting", label: "Reporting", icon: PieChart, path: "/reporting" },
      { id: "process-mining", label: "Process Mining", icon: GitBranch, path: "/process-mining" },
      {
        id: "analytics-bi",
        label: "Analytics & BI",
        icon: Database,
        children: [
          { id: "data-warehouse", label: "Data Warehouse", path: "/data-warehouse" },
          { id: "data-export-lab", label: "Data Export Lab", path: "/data-lab" },
          { id: "optimization-engine", label: "Optimization Engine", path: "/optimization" },
          { id: "decision-impact", label: "Decision Impact", path: "/decision-impact" },
          { id: "sql-explorer", label: "SQL Explorer", path: "/sql-explorer" },
          { id: "role-dashboard", label: "Role Dashboard", path: "/role-dashboard" },
        ],
      },
      { id: "benchmark", label: "Benchmark", icon: Scale, path: "/benchmark" },
    ],
  },
  {
    id: "simulation",
    label: "Simulation & Labs",
    icon: FlaskConical,
    children: [
      { id: "scenario-simulator", label: "Scenario Simulator", icon: Activity, path: "/scenarios/simulator" },
      { id: "simulation-session", label: "Simulation", icon: Users, path: "/simulation" },
      { id: "experiment-lab", label: "Experiment Lab", icon: FlaskConical, path: "/experiment-lab" },
      { id: "scenario-replay", label: "Scenario Replay", icon: Film, path: "/scenario-replay" },
      { id: "simulator", label: "Simulator", icon: Gamepad2, path: "/simulator" },
      { id: "erp-explainer", label: "ERP Explainer", icon: HelpCircle, path: "/explainer" },
      { id: "erp-copilot", label: "ERP Copilot", icon: Bot, path: "/copilot" },
      { id: "ai-recommendations", label: "AI Recommendations", icon: Brain, path: "/recommendations" },
      { id: "time-machine", label: "Time Machine", icon: Clock, path: "/time-machine" },
      { id: "event-bus", label: "Event Bus", icon: Zap, path: "/event-bus" },
      { id: "stress-test", label: "Stress Test", icon: Zap, path: "/stress-test" },
    ],
  },
  {
    id: "administration",
    label: "Administration",
    icon: Settings,
    children: [
      {
        id: "workflow",
        label: "Workflow",
        icon: GitBranch,
        children: [
          { id: "workflow-list", label: "Workflows", path: "/workflow" },
          { id: "workflow-builder", label: "Workflow Builder", path: "/workflow/builder", roles: STAFF },
        ],
      },
      {
        id: "instructor",
        label: "Instructor",
        icon: Shield,
        roles: STAFF,
        children: [
          { id: "instructor-control", label: "Control Panel", path: "/instructor" },
          { id: "instructor-analytics", label: "Class Analytics", path: "/instructor/analytics" },
          { id: "instructor-assignments", label: "Assignment Builder", path: "/instructor/assignments" },
          { id: "instructor-scenarios", label: "Scenario Builder", path: "/instructor/scenarios" },
          { id: "sandbox", label: "Sandbox Manager", path: "/sandbox" },
          { id: "dataset-generator", label: "Dataset Generator", path: "/dataset-generator" },
          { id: "industry-templates", label: "Industry Templates", path: "/industry-templates" },
        ],
      },
      {
        id: "utilities",
        label: "Utilities",
        icon: Terminal,
        children: [
          { id: "api-playground", label: "API Playground", path: "/tools/api-playground" },
          { id: "sql-explorer-utility", label: "SQL Explorer", path: "/sql-explorer" },
        ],
      },
      { id: "integration", label: "Integration", icon: Link2, path: "/integration", roles: STAFF },
      { id: "documents", label: "Documents", icon: FileText, path: "/documents" },
      { id: "portals", label: "Portals", icon: Globe, path: "/portals", roles: STAFF },
      { id: "audit", label: "Audit", icon: Eye, path: "/audit" },
      { id: "monitoring", label: "Monitoring", icon: Gauge, path: "/monitoring", roles: ADMIN_ONLY },
      { id: "admin", label: "Admin", icon: Settings, path: "/admin", roles: ADMIN_ONLY },
    ],
  },
];

interface Props {
  isOpen: boolean;
  onClose: () => void;
}

export default function Sidebar({ isOpen, onClose }: Props) {
  const location = useLocation();
  const { user } = useAuthStore();
  const userRoles = user?.roles ?? [];

  const visibleItems = useMemo(() => {
    const filterItems = (items: NavItem[]): NavItem[] => {
      return items.reduce<NavItem[]>((acc, item) => {
        if (item.roles && !item.roles.some((r) => userRoles.includes(r))) {
          return acc;
        }
        if (!item.children) {
          acc.push(item);
          return acc;
        }
        const children = filterItems(item.children);
        if (children.length === 0) return acc;
        acc.push({ ...item, children });
        return acc;
      }, []);
    };

    return filterItems(navItems);
  }, [userRoles]);

  const isPathActive = (path?: string) => {
    if (!path) return false;
    if (path === "/") return location.pathname === "/";
    return location.pathname.startsWith(path);
  };

  const isItemActive = (item: NavItem): boolean => {
    if (item.path) return isPathActive(item.path);
    return item.children?.some(isItemActive) ?? false;
  };

  const [expanded, setExpanded] = useState<Record<string, boolean>>(() => {
    const init: Record<string, boolean> = {};
    const markExpanded = (items: NavItem[]) => {
      items.forEach((item) => {
        if (item.children?.length) {
          if (item.children.some((child) => isItemActive(child))) {
            init[item.id] = true;
          }
          markExpanded(item.children);
        }
      });
    };
    markExpanded(navItems);
    return init;
  });

  const toggle = (id: string) =>
    setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));

  const linkClass = (isActive: boolean, depth = 0) =>
    `flex items-center gap-3 px-3 py-2 rounded-lg transition-colors ${
      depth > 1 ? "text-xs" : "text-sm"
    } ${
      isActive
        ? "bg-primary-600/10 text-primary-600 font-medium"
        : "text-gray-400 hover:text-white hover:bg-white/5"
    }`;

  const renderItems = (items: NavItem[], depth = 0) =>
    items.map((item) => {
      const hasChildren = !!item.children?.length;
      const isExpanded = expanded[item.id];
      const isActive = isItemActive(item);
      const paddingLeft = 12 + depth * 12;

      if (!hasChildren && item.path) {
        return (
          <NavLink
            key={item.id}
            to={item.path}
            onClick={onClose}
            className={linkClass(isPathActive(item.path), depth)}
            style={{ paddingLeft }}
            end={item.path === "/"}
          >
            {item.icon && <item.icon className="w-4 h-4 flex-shrink-0" />}
            <span>{item.label}</span>
          </NavLink>
        );
      }

      return (
        <div key={item.id}>
          <button
            onClick={() => toggle(item.id)}
            className={`flex items-center gap-3 px-3 py-2 rounded-lg w-full transition-colors ${
              depth > 1 ? "text-xs" : "text-sm"
            } ${
              isActive
                ? "text-primary-400"
                : "text-gray-400 hover:text-white hover:bg-white/5"
            }`}
            style={{ paddingLeft }}
          >
            {item.icon ? (
              <item.icon className="w-4 h-4 flex-shrink-0" />
            ) : (
              <span className="w-4 h-4" />
            )}
            <span className="flex-1 text-left">{item.label}</span>
            {isExpanded ? (
              <ChevronDown className="w-3.5 h-3.5" />
            ) : (
              <ChevronRight className="w-3.5 h-3.5" />
            )}
          </button>
          {isExpanded && item.children && (
            <div
              className="mt-1 space-y-0.5 border-l border-gray-800"
              style={{ marginLeft: paddingLeft, paddingLeft: 12 }}
            >
              {renderItems(item.children, depth + 1)}
            </div>
          )}
        </div>
      );
    });

  return (
    <>
      {isOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-40 lg:hidden"
          onClick={onClose}
        />
      )}
      <aside
        className={`fixed top-0 left-0 z-50 h-screen w-64 bg-sap-sidebar border-r border-gray-800 flex flex-col transition-transform lg:translate-x-0 ${
          isOpen ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        <div className="flex items-center justify-between h-16 px-4 border-b border-gray-800">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-primary-600 flex items-center justify-center">
              <Landmark className="w-4 h-4 text-white" />
            </div>
            <div>
              <h1 className="text-sm font-bold text-white tracking-tight">
                SAP ERP
              </h1>
              <p className="text-[10px] text-gray-500 uppercase tracking-wider">
                Learning Platform
              </p>
            </div>
          </div>
          <button onClick={onClose} className="lg:hidden text-gray-400 hover:text-white">
            <X className="w-5 h-5" />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto p-3 space-y-1">
          {renderItems(visibleItems)}
        </nav>

        <div className="p-3 border-t border-gray-800">
          <div className="px-3 py-2 text-xs text-gray-600">
            v3.0.0 &middot; Enterprise Edition
          </div>
        </div>
      </aside>
    </>
  );
}
