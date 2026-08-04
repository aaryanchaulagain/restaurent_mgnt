import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import {
  ApproximateDistanceLabel,
  BranchEligibilityBadge,
  BranchRecommendationList,
} from "./branch-recommendation-list";
import type { BranchRecommendationRow } from "@/features/public-business/api/public-business-api";

vi.mock("next/link", () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

const base = (overrides: Partial<BranchRecommendationRow> = {}): BranchRecommendationRow => ({
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
  distance_km: 3.4,
  recommended: false,
  eligibility_reasons: [],
  address: { city: "Itahari", state: "Koshi" },
  ...overrides,
});

describe("BranchRecommendationList", () => {
  it("labels recommended branch and shows distance", () => {
    render(
      <BranchRecommendationList
        businessSlug="aryan-grocery"
        branches={[
          base({ recommended: true, name: "Far Eligible", distance_km: 8.2 }),
          base({
            public_id: "br-2",
            name: "Near Ineligible",
            recommended: false,
            eligible: false,
            delivery_eligible: false,
            distance_km: 1.1,
            eligibility_reasons: ["OUTSIDE_SERVICE_AREA"],
          }),
        ]}
      />,
    );
    expect(screen.getByText("Recommended")).toBeInTheDocument();
    expect(screen.getAllByText("Outside delivery area").length).toBeGreaterThan(0);
    expect(screen.getByText(/Approximately 8.2 km away/)).toBeInTheDocument();
    expect(screen.getAllByText("Choose this branch").length).toBe(2);
  });

  it("shows distance unavailable", () => {
    render(<ApproximateDistanceLabel km={null} />);
    expect(screen.getByText("Distance unavailable")).toBeInTheDocument();
  });

  it("marks eligible delivery badge", () => {
    render(<BranchEligibilityBadge branch={base({ recommended: false, delivery_eligible: true })} />);
    expect(screen.getByText("Delivers to your area")).toBeInTheDocument();
  });
});
