import { describe, expect, it } from "vitest";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";
import { ownershipLabel, revenueOwnershipWording } from "@/features/payments/utils/ownership-label";

describe("paymentErrorMessage", () => {
  it("maps PAYMENT_ACCOUNT_NOT_READY", () => {
    expect(paymentErrorMessage("PAYMENT_ACCOUNT_NOT_READY", "x")).toMatch(/not ready/i);
  });
  it("falls back for unknown codes", () => {
    expect(paymentErrorMessage("UNKNOWN", "Custom")).toBe("Custom");
  });
});

describe("ownership-label", () => {
  it("maps ownership types", () => {
    expect(ownershipLabel("first_party")).toBe("Suvakamana-owned");
    expect(ownershipLabel("third_party")).toBe("Partner restaurant");
  });
  it("maps revenue wording", () => {
    expect(revenueOwnershipWording("first_party")).toBe("Platform-owned revenue");
    expect(revenueOwnershipWording("third_party")).toMatch(/restaurant share/i);
  });
});
