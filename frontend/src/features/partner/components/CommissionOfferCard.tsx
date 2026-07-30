"use client";

import { Button } from "@/components/ui/button";
import type { CommissionAgreement } from "../types";

export function CommissionOfferCard({
  agreement,
  onAccept,
  accepting,
}: {
  agreement: CommissionAgreement | null | undefined;
  onAccept?: () => void;
  accepting?: boolean;
}) {
  if (!agreement) {
    return (
      <p className="text-sm text-[var(--text-secondary)]">
        Commission terms will appear here once offered by our team.
      </p>
    );
  }

  return (
    <div className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-4 text-sm">
      <p className="font-medium">Commission offer</p>
      <dl className="mt-3 space-y-1">
        <Row label="Type" value={agreement.commission_type.replace(/_/g, " ")} />
        {agreement.commission_rate != null ? (
          <Row label="Rate" value={`${agreement.commission_rate}%`} />
        ) : null}
        {agreement.fixed_fee_cents != null ? (
          <Row label="Fixed fee" value={`$${(agreement.fixed_fee_cents / 100).toFixed(2)}`} />
        ) : null}
        <Row label="Settlement" value={agreement.settlement_frequency} />
        <Row label="Status" value={agreement.status} />
      </dl>
      {onAccept && agreement.status === "offered" && !agreement.accepted_at ? (
        <Button className="mt-4" onClick={onAccept} disabled={accepting}>
          {accepting ? "Accepting…" : "Accept commission terms"}
        </Button>
      ) : agreement.accepted_at ? (
        <p className="mt-3 text-xs text-[var(--text-muted)]">
          Accepted {new Date(agreement.accepted_at).toLocaleString()}
        </p>
      ) : null}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-[var(--text-muted)]">{label}</dt>
      <dd className="font-medium capitalize">{value}</dd>
    </div>
  );
}
