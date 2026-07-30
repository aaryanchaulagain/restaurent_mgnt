import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { PlaceOrderButton } from "./place-order-button";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const place = vi.fn();
vi.mock("@/features/orders/api/order-api", () => ({
  customerOrderApi: { place: (...args: unknown[]) => place(...args) },
}));

describe("PlaceOrderButton", () => {
  beforeEach(() => {
    push.mockReset();
    place.mockReset();
    place.mockResolvedValue({
      data: {
        order: { order_number: "SVK-20260729-000001", guest_access_token: "tok" },
      },
    });
  });

  afterEach(() => cleanup());

  it("sends idempotency key on place", async () => {
    render(
      <PlaceOrderButton
        quotePublicId="quote-uuid"
        customerName="Alex"
        customerEmail="a@example.com"
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: /place order \(cash\)/i }));
    await waitFor(() => expect(place).toHaveBeenCalledTimes(1));
    const body = place.mock.calls[0][0];
    expect(body.checkout_quote_public_id).toBe("quote-uuid");
    expect(body.idempotency_key).toBeTruthy();
    expect(body.payment_method).toBe("cash");
  });

  it("does not double-submit while in flight", async () => {
    let resolve!: (v: unknown) => void;
    place.mockReturnValue(new Promise((r) => { resolve = r; }));
    render(
      <PlaceOrderButton quotePublicId="q" customerName="A" customerEmail="a@b.com" />,
    );
    const btn = screen.getByRole("button", { name: /place order \(cash\)/i });
    fireEvent.click(btn);
    fireEvent.click(btn);
    expect(place).toHaveBeenCalledTimes(1);
    resolve({ data: { order: { order_number: "SVK-1" } } });
    await waitFor(() => expect(push).toHaveBeenCalledWith("/orders/SVK-1"));
  });
});
