import { describe, expect, it } from "vitest";
import { emptyTypeDetailsFor } from "@/features/restaurant/lib/menu-item-type-details";

describe("menu-item-type-details", () => {
  it("returns bakery grocery and butcher defaults", () => {
    expect(emptyTypeDetailsFor("bakery")).toMatchObject({ schema: "bakery", eggless: false });
    expect(emptyTypeDetailsFor("grocery")).toMatchObject({ schema: "grocery", brand: "" });
    expect(emptyTypeDetailsFor("butcher")).toMatchObject({
      schema: "butcher",
      fixed_weight_variants: [],
    });
  });

  it("returns null for restaurant", () => {
    expect(emptyTypeDetailsFor("restaurant")).toBeNull();
  });
});
