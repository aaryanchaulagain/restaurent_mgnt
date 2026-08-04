import { describe, expect, it } from "vitest";
import { cartBranchLabel, cartLocality } from "./cart-branch-label";
import type { CartState } from "../api/cart-api";

const base: CartState = {
  public_id: "c1",
  version: 1,
  currency: "AUD",
  restaurant: { slug: "aryan-grocery-itahari", trading_name: "Aryan Grocery Itahari" },
  items: [],
};

describe("cartBranchLabel", () => {
  it("prefers business and branch names", () => {
    expect(
      cartBranchLabel({
        ...base,
        business: {
          public_id: "b1",
          slug: "aryan-grocery",
          name: "Aryan Grocery",
          business_type: "grocery",
        },
        branch: { public_id: "br1", name: "Itahari Branch", city: "Itahari", state: "Koshi" },
      }),
    ).toBe("Aryan Grocery — Itahari Branch");
  });

  it("falls back to restaurant trading name", () => {
    expect(cartBranchLabel(base)).toBe("Aryan Grocery Itahari");
  });
});

describe("cartLocality", () => {
  it("joins city and state", () => {
    expect(
      cartLocality({
        ...base,
        branch: { public_id: "br1", name: "Itahari Branch", city: "Itahari", state: "Koshi" },
      }),
    ).toBe("Itahari, Koshi");
  });

  it("returns null without branch", () => {
    expect(cartLocality(base)).toBeNull();
  });
});
