import { getBusinessTypeConfig } from "@/features/business/config/business-type-config";

export type NavItem = {
  href: string;
  label: string;
  /** Any of these permissions grants visibility. Empty = always show when authenticated. */
  permissions?: string[];
};

export const restaurantNavItems: NavItem[] = [
  {
    href: "/restaurant/dashboard",
    label: "Dashboard",
    permissions: ["view_restaurant_dashboard"],
  },
  {
    href: "/restaurant/branches",
    label: "Branches",
    permissions: ["view_all_business_branches", "manage_business_branches"],
  },
  {
    href: "/restaurant/orders",
    label: "Orders",
    permissions: ["view_orders", "view_restaurant_orders"],
  },
  {
    href: "/restaurant/menu",
    label: "Menu",
    permissions: ["view_menu"],
  },
  {
    href: "/restaurant/inventory",
    label: "Inventory",
    permissions: ["view_inventory"],
  },
  {
    href: "/restaurant/offers",
    label: "Offers",
    permissions: ["manage_offers", "manage_restaurant_offers"],
  },
  {
    href: "/restaurant/finance",
    label: "Finance",
    permissions: ["view_finance", "view_restaurant_payment_summaries"],
  },
  {
    href: "/restaurant/reports",
    label: "Reports",
    permissions: ["view_branch_reports", "view_business_reports"],
  },
  {
    href: "/restaurant/settlements",
    label: "Settlements",
    permissions: ["view_settlements"],
  },
  {
    href: "/restaurant/staff",
    label: "Staff",
    permissions: [
      "view_branch_staff",
      "manage_branch_staff",
      "manage_restaurant_staff",
      "invite_branch_staff",
    ],
  },
  {
    href: "/restaurant/profile",
    label: "Profile",
    permissions: ["view_restaurant_profile"],
  },
  {
    href: "/restaurant/settings",
    label: "Settings",
    permissions: ["manage_restaurant_hours", "manage_restaurant_settings", "view_restaurant_profile"],
  },
  {
    href: "/restaurant/settings/payments",
    label: "Payments",
    permissions: ["manage_payment_accounts"],
  },
];

/** @deprecated Prefer restaurantNavFor with permissions */
export const restaurantNav = restaurantNavItems.map(({ href, label }) => ({ href, label }));

export function filterNavByPermissions(
  items: NavItem[],
  permissions: string[] | null | undefined,
  businessType?: string | null,
): Array<{ href: string; label: string }> {
  const copy = getBusinessTypeConfig(businessType);
  const effective = permissions ?? null;

  return items
    .filter((item) => {
      if (item.href === "/restaurant/inventory" && !copy.supportsInventory) {
        return false;
      }
      if (!item.permissions || item.permissions.length === 0) {
        return true;
      }
      // Until authorization loads, show only the dashboard to avoid flashing privileged nav.
      if (effective === null) {
        return item.href === "/restaurant/dashboard";
      }
      return item.permissions.some((p) => effective.includes(p));
    })
    .map((item) =>
      item.href === "/restaurant/menu"
        ? { href: item.href, label: copy.catalogueLabel }
        : { href: item.href, label: item.label },
    );
}

/** Same routes; catalogue nav label follows business type. */
export function restaurantNavFor(
  businessType?: string | null,
  permissions?: string[] | null,
) {
  return filterNavByPermissions(restaurantNavItems, permissions, businessType);
}

export const adminNav = [
  { href: "/admin/dashboard", label: "Dashboard" },
  { href: "/admin/applications", label: "Applications" },
  { href: "/admin/orders", label: "Orders" },
  { href: "/admin/restaurants", label: "Partners" },
  { href: "/admin/menus", label: "Menus" },
  { href: "/admin/commissions", label: "Commissions" },
  { href: "/admin/settlements", label: "Settlements" },
  { href: "/admin/payments", label: "Payments" },
  { href: "/admin/refunds", label: "Refunds" },
  { href: "/admin/disputes", label: "Disputes" },
  { href: "/admin/support", label: "Support" },
  { href: "/admin/audit-logs", label: "Audit logs" },
  { href: "/admin/settings", label: "Settings" },
];

/** Map a path to the permission(s) required to view that module page. */
export function requiredPermissionsForPath(pathname: string): string[] | null {
  const match = [...restaurantNavItems]
    .sort((a, b) => b.href.length - a.href.length)
    .find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));
  return match?.permissions ?? null;
}
