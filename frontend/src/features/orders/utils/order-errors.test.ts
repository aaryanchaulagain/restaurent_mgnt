import { describe, expect, it } from "vitest";
import { ownershipLabel } from "@/features/orders/api/admin-order-api";
import { orderErrorMessage } from "@/features/orders/utils/order-errors";

describe("admin order helpers", () => {
  it("maps ownership to friendly labels", () => {
    expect(ownershipLabel("first_party")).toBe("Khana-operated");
    expect(ownershipLabel("third_party")).toBe("Partner restaurant");
  });
});

describe("order error codes", () => {
  it("maps IDEMPOTENCY_KEY_REUSED", () => {
    expect(orderErrorMessage("IDEMPOTENCY_KEY_REUSED", "x")).toMatch(/already submitted/i);
  });
  it("maps PRICE_CHANGED", () => {
    expect(orderErrorMessage("PRICE_CHANGED", "x")).toMatch(/Prices changed/i);
  });
  it("maps INVALID_ORDER_TRANSITION", () => {
    expect(orderErrorMessage("INVALID_ORDER_TRANSITION", "x")).toMatch(/not available/i);
  });
});
