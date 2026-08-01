import { describe, expect, it } from "vitest";
import {
  getBusinessTypeConfig,
  normalizeBusinessType,
} from "@/features/business/config/business-type-config";

describe("business-type-config", () => {
  it("normalizes aliases and unknown values", () => {
    expect(normalizeBusinessType("butchery")).toBe("butcher");
    expect(normalizeBusinessType("grocery_store")).toBe("grocery");
    expect(normalizeBusinessType("meat_shop")).toBe("butcher");
    expect(normalizeBusinessType(null)).toBe("other");
    expect(normalizeBusinessType("cafe")).toBe("other");
  });

  it("hides prep time and modifiers for grocery", () => {
    const grocery = getBusinessTypeConfig("grocery");
    expect(grocery.catalogueLabel).toBe("Products");
    expect(grocery.supportsPreparationTime).toBe(false);
    expect(grocery.supportsModifiers).toBe(false);
  });

  it("keeps restaurant menu terminology", () => {
    const restaurant = getBusinessTypeConfig("restaurant");
    expect(restaurant.catalogueLabel).toBe("Menu");
    expect(restaurant.addProductLabel).toBe("Add menu item");
    expect(restaurant.supportsDietary).toBe(true);
  });

  it("enables type details for bakery grocery and butcher", () => {
    expect(getBusinessTypeConfig("bakery").supportsTypeDetails).toBe(true);
    expect(getBusinessTypeConfig("grocery").supportsTypeDetails).toBe(true);
    expect(getBusinessTypeConfig("butcher").supportsTypeDetails).toBe(true);
    expect(getBusinessTypeConfig("restaurant").supportsTypeDetails).toBe(false);
  });
});
