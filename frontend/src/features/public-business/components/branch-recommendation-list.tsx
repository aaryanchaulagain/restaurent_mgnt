"use client";

import Link from "next/link";
import { Badge } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import type { BranchRecommendationRow } from "@/features/public-business/api/public-business-api";

function reasonLabel(code: string): string {
  switch (code) {
    case "OUTSIDE_SERVICE_AREA":
      return "Outside delivery area";
    case "DELIVERY_NOT_SUPPORTED":
      return "Delivery not available";
    case "PICKUP_NOT_SUPPORTED":
      return "Pickup not available";
    case "BRANCH_NOT_ACCEPTING_ORDERS":
      return "Not accepting orders";
    case "CUSTOMER_LOCATION_UNAVAILABLE":
      return "Location needed for delivery radius";
    case "BRANCH_RESTAURANT_INVALID":
      return "Unavailable";
    default:
      return code.replaceAll("_", " ").toLowerCase();
  }
}

export function ApproximateDistanceLabel({ km }: { km: number | null }) {
  if (km === null || km === undefined) {
    return <span className="text-xs text-[var(--text-muted)]">Distance unavailable</span>;
  }
  return (
    <span className="text-xs text-[var(--text-secondary)]">Approximately {km.toFixed(1)} km away</span>
  );
}

export function BranchEligibilityBadge({ branch }: { branch: BranchRecommendationRow }) {
  if (branch.recommended) {
    return <Badge tone="accent">Recommended</Badge>;
  }
  if (branch.eligible) {
    return branch.delivery_eligible ? (
      <Badge tone="success">Delivers to your area</Badge>
    ) : (
      <Badge tone="success">Pickup available</Badge>
    );
  }
  if (branch.eligibility_reasons.includes("OUTSIDE_SERVICE_AREA")) {
    return <Badge tone="warning">Outside delivery area</Badge>;
  }
  if (branch.eligibility_reasons.includes("BRANCH_NOT_ACCEPTING_ORDERS")) {
    return <Badge tone="warning">Not accepting orders</Badge>;
  }
  return <Badge tone="neutral">Not eligible</Badge>;
}

export function BranchRecommendationList({
  businessSlug,
  branches,
}: {
  businessSlug: string;
  branches: BranchRecommendationRow[];
}) {
  if (branches.length === 0) {
    return (
      <p className="text-sm text-[var(--text-secondary)]">No locations matched this search.</p>
    );
  }

  return (
    <ul className="grid gap-4 sm:grid-cols-2">
      {branches.map((branch) => {
        const locality = [branch.address?.city, branch.address?.state].filter(Boolean).join(", ");
        return (
          <li
            key={branch.public_id}
            className="rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-5 shadow-[var(--shadow-sm)]"
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="font-[family-name:var(--font-display)] text-xl text-[var(--text-primary)]">
                  {branch.name}
                </h3>
                {locality ? <p className="mt-1 text-sm text-[var(--text-secondary)]">{locality}</p> : null}
                <div className="mt-2">
                  <ApproximateDistanceLabel km={branch.distance_km} />
                </div>
              </div>
              <div className="flex flex-col items-end gap-1">
                <BranchEligibilityBadge branch={branch} />
                {branch.is_temporarily_closed ? (
                  <Badge tone="warning">Temporarily closed</Badge>
                ) : branch.is_open_now ? (
                  <Badge tone="success">Open now</Badge>
                ) : (
                  <Badge tone="info">Closed</Badge>
                )}
              </div>
            </div>
            {branch.eligibility_reasons.length > 0 ? (
              <ul className="mt-3 space-y-1 text-xs text-[var(--text-muted)]">
                {branch.eligibility_reasons.map((r) => (
                  <li key={r}>{reasonLabel(r)}</li>
                ))}
              </ul>
            ) : null}
            <div className="mt-4">
              <Link href={`/businesses/${businessSlug}/branches/${branch.public_id}`}>
                <Button type="button" variant={branch.recommended ? "primary" : "outline"} className="w-full">
                  Choose this branch
                </Button>
              </Link>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
