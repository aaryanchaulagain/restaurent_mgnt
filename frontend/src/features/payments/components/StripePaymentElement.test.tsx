import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import type { ReactNode } from "react";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const confirmPayment = vi.fn();
vi.mock("@stripe/react-stripe-js", () => ({
  Elements: ({ children }: { children: ReactNode }) => <div data-testid="elements">{children}</div>,
  PaymentElement: () => <div data-testid="payment-element" />,
  useStripe: () => ({ confirmPayment }),
  useElements: () => ({}),
}));

vi.mock("@stripe/stripe-js", () => ({
  loadStripe: vi.fn(() => Promise.resolve({})),
}));

import { StripePaymentElement } from "./StripePaymentElement";

describe("StripePaymentElement", () => {
  beforeEach(() => {
    push.mockReset();
    confirmPayment.mockReset();
    vi.stubEnv("NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY", "pk_test_mock");
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllEnvs();
  });

  it("redirects to processing after confirm — does not show paid in UI", async () => {
    confirmPayment.mockResolvedValue({ error: undefined });
    render(
      <StripePaymentElement orderNumber="SVK-1" clientSecret="cs_test_123" publishableKey={null} />,
    );
    fireEvent.click(screen.getByRole("button", { name: /pay now/i }));
    await waitFor(() => expect(confirmPayment).toHaveBeenCalled());
    await waitFor(() => expect(push).toHaveBeenCalledWith("/orders/SVK-1/payment/processing"));
    expect(screen.queryByText(/^Paid$/i)).not.toBeInTheDocument();
  });
});
