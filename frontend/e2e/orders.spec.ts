import { test, expect } from "@playwright/test";

test.describe("Public order surfaces", () => {
  test("guest track requires order number and tracking token fields", async ({ page }) => {
    await page.goto("/orders/track");
    await expect(page.getByRole("heading", { name: /track your order/i })).toBeVisible({ timeout: 30000 });
    await expect(page.locator("#orderNumber")).toBeVisible();
    await expect(page.locator("#token")).toBeVisible();
  });

  test("home page does not claim payment successful", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByText(/payment successful/i)).toHaveCount(0);
  });
});

test.describe("Checkout payment selection", () => {
  test("checkout page loads without claiming payment successful", async ({ page }) => {
    await page.goto("/checkout");
    await expect(page.getByText(/payment successful/i)).toHaveCount(0);
    await expect(page.locator("main")).toBeVisible({ timeout: 30000 });
  });
});
