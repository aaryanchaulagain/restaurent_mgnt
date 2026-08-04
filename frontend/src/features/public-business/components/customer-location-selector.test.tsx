import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor, cleanup, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CustomerLocationSelector } from "./customer-location-selector";

const recommendBranches = vi.fn();
vi.mock("@/features/public-business/api/public-business-api", async () => {
  const actual = await vi.importActual<typeof import("@/features/public-business/api/public-business-api")>(
    "@/features/public-business/api/public-business-api",
  );
  return {
    ...actual,
    publicBusinessApi: {
      ...actual.publicBusinessApi,
      recommendBranches: (...args: unknown[]) => recommendBranches(...args),
    },
  };
});

vi.mock("@/features/auth/hooks/use-auth", () => ({
  useAuth: () => ({ isAuthenticated: false }),
}));

vi.mock("@/features/public-business/lib/browser-location", () => ({
  requestBrowserLocation: vi.fn(),
}));

vi.mock("next/link", () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

function renderSelector() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <CustomerLocationSelector businessSlug="aryan-grocery" />
    </QueryClientProvider>,
  );
}

describe("CustomerLocationSelector", () => {
  beforeEach(() => {
    cleanup();
    recommendBranches.mockReset();
    recommendBranches.mockResolvedValue({
      data: {
        business: { public_id: "b", slug: "aryan-grocery", name: "Aryan Grocery" },
        fulfilment: "delivery",
        location: { postcode: "4000", coordinates_used: false },
        recommended_branch_public_id: "br-1",
        branches: [
          {
            public_id: "br-1",
            name: "Itahari Branch",
            is_publicly_browsable: true,
            is_temporarily_closed: false,
            is_open_now: true,
            accepting_orders: true,
            supports_delivery: true,
            supports_pickup: true,
            delivery_eligible: true,
            pickup_eligible: true,
            eligible: true,
            distance_km: null,
            recommended: true,
            eligibility_reasons: [],
          },
        ],
      },
    });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it("requests browser location only after explicit consent click", async () => {
    const { requestBrowserLocation } = await import("@/features/public-business/lib/browser-location");
    vi.mocked(requestBrowserLocation).mockResolvedValue({
      ok: false,
      reason: "denied",
    });
    const { container } = renderSelector();
    const view = within(container);

    expect(requestBrowserLocation).not.toHaveBeenCalled();
    fireEvent.click(view.getByRole("button", { name: "Use my current location" }));
    expect(view.getByText(/will not be saved unless you choose to save it/i)).toBeInTheDocument();
    expect(requestBrowserLocation).not.toHaveBeenCalled();

    fireEvent.click(view.getByRole("button", { name: "Allow location" }));
    await waitFor(() => expect(requestBrowserLocation).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(view.getByText(/Location permission denied/i)).toBeInTheDocument());
  });

  it("runs postcode recommendation without touching cart APIs", async () => {
    const { container } = renderSelector();
    const view = within(container);

    fireEvent.change(view.getByLabelText("Postcode"), { target: { value: "4000" } });
    fireEvent.click(view.getByRole("button", { name: "Find branches" }));

    await waitFor(() => expect(recommendBranches).toHaveBeenCalled());
    expect(recommendBranches.mock.calls[0][0]).toBe("aryan-grocery");
    expect(recommendBranches.mock.calls[0][1]).toMatchObject({
      fulfilment: "delivery",
      postcode: "4000",
    });
    await waitFor(() => expect(view.getByText("Recommended")).toBeInTheDocument());
  });
});
