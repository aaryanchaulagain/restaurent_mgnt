import { getBusinessTypeConfig } from "@/features/business/config/business-type-config";

export const restaurantNav = [
  { href: "/restaurant/dashboard", label: "Dashboard" },
  { href: "/restaurant/branches", label: "Branches" },
  { href: "/restaurant/orders", label: "Orders" },
  { href: "/restaurant/menu", label: "Menu" },
  { href: "/restaurant/inventory", label: "Inventory" },
  { href: "/restaurant/offers", label: "Offers" },
  { href: "/restaurant/finance", label: "Finance" },
  { href: "/restaurant/settlements", label: "Settlements" },
  { href: "/restaurant/staff", label: "Staff" },
  { href: "/restaurant/profile", label: "Profile" },
  { href: "/restaurant/settings", label: "Settings" },
  { href: "/restaurant/settings/payments", label: "Payments" },
];

/** Same routes; catalogue nav label follows business type. */
export function restaurantNavFor(businessType?: string | null) {
  const copy = getBusinessTypeConfig(businessType);
  return restaurantNav
    .filter((item) => (item.href === "/restaurant/inventory" ? copy.supportsInventory : true))
    .map((item) =>
      item.href === "/restaurant/menu" ? { ...item, label: copy.catalogueLabel } : item,
    );
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
