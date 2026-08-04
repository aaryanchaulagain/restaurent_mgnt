import { describe, expect, it } from "vitest";
import {
  filterNavByPermissions,
  restaurantNavItems,
  requiredPermissionsForPath,
} from "@/lib/admin-nav";

describe("permission-aware restaurant navigation", () => {
  it("shows only dashboard while permissions are loading", () => {
    const items = filterNavByPermissions(restaurantNavItems, null, "restaurant");
    expect(items.map((i) => i.href)).toEqual(["/restaurant/dashboard"]);
  });

  it("filters modules for a branch manager", () => {
    const perms = [
      "view_restaurant_dashboard",
      "view_orders",
      "view_restaurant_orders",
      "view_menu",
      "view_inventory",
      "view_branch_staff",
      "view_restaurant_profile",
      "manage_restaurant_hours",
      "view_restaurant_payment_summaries",
    ];
    const hrefs = filterNavByPermissions(restaurantNavItems, perms, "restaurant").map(
      (i) => i.href,
    );
    expect(hrefs).toContain("/restaurant/orders");
    expect(hrefs).toContain("/restaurant/menu");
    expect(hrefs).toContain("/restaurant/inventory");
    expect(hrefs).toContain("/restaurant/staff");
    expect(hrefs).not.toContain("/restaurant/settings/payments");
    expect(hrefs).not.toContain("/restaurant/settlements");
    expect(hrefs).not.toContain("/restaurant/branches");
  });

  it("shows inventory for inventory managers", () => {
    const hrefs = filterNavByPermissions(
      restaurantNavItems,
      ["view_restaurant_dashboard", "view_inventory", "view_menu"],
      "grocery",
    ).map((i) => i.href);
    expect(hrefs).toContain("/restaurant/inventory");
    expect(hrefs).toContain("/restaurant/menu");
    expect(hrefs).not.toContain("/restaurant/orders");
    expect(hrefs).not.toContain("/restaurant/staff");
  });

  it("shows orders for kitchen staff without finance", () => {
    const hrefs = filterNavByPermissions(
      restaurantNavItems,
      [
        "view_restaurant_dashboard",
        "view_orders",
        "view_restaurant_orders",
        "prepare_restaurant_orders",
        "view_menu",
      ],
      "restaurant",
    ).map((i) => i.href);
    expect(hrefs).toContain("/restaurant/orders");
    expect(hrefs).not.toContain("/restaurant/finance");
    expect(hrefs).not.toContain("/restaurant/staff");
    expect(hrefs).not.toContain("/restaurant/inventory");
  });

  it("maps paths to required permissions", () => {
    expect(requiredPermissionsForPath("/restaurant/inventory")).toContain("view_inventory");
    expect(requiredPermissionsForPath("/restaurant/settings/payments")).toContain(
      "manage_payment_accounts",
    );
  });
});
