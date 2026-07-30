"use client";

import { Badge } from "@/components/ui/feedback";
import type { RestaurantPaymentAccount } from "@/features/payments/api/payment-api";
import { ownershipLabel } from "@/features/payments/utils/ownership-label";

function onboardingLabel(status?: string): string {
  switch (status) {
    case "not_started":
      return "Not started";
    case "pending":
      return "In progress";
    case "active":
      return "Active";
    case "restricted":
      return "Restricted";
    case "disabled":
      return "Disabled";
    default:
      return status?.replace(/_/g, " ") ?? "Unknown";
  }
}

function onboardingTone(status?: string): "success" | "warning" | "error" | "info" {
  if (status === "active") return "success";
  if (status === "restricted" || status === "disabled") return "error";
  if (status === "pending" || status === "not_started") return "warning";
  return "info";
}

type Props = {
  account: RestaurantPaymentAccount;
};

export function PaymentAccountStatusCard({ account }: Props) {
  if (account.ownership_type === "first_party") {
    return (
      <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
        <h2 className="text-xl font-semibold">Payment account</h2>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          {ownershipLabel("first_party")} — {account.message ?? "Online payments settle on the platform account."}
        </p>
        <Badge tone="success" className="mt-3">
          Online payments enabled
        </Badge>
      </section>
    );
  }

  const status = account.onboarding_status ?? "not_started";

  return (
    <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">Stripe Connect</h2>
          <p className="mt-1 text-sm text-[var(--text-muted)]">{ownershipLabel("third_party")}</p>
        </div>
        <Badge tone={onboardingTone(status)}>{onboardingLabel(status)}</Badge>
      </div>
      <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-2">
        <div className="flex justify-between gap-2 sm:flex-col sm:justify-start">
          <dt className="text-[var(--text-muted)]">Charges</dt>
          <dd>{account.charges_enabled ? "Enabled" : "Not enabled"}</dd>
        </div>
        <div className="flex justify-between gap-2 sm:flex-col sm:justify-start">
          <dt className="text-[var(--text-muted)]">Payouts</dt>
          <dd>{account.payouts_enabled ? "Enabled" : "Not enabled"}</dd>
        </div>
        <div className="flex justify-between gap-2 sm:flex-col sm:justify-start">
          <dt className="text-[var(--text-muted)]">Details submitted</dt>
          <dd>{account.details_submitted ? "Yes" : "No"}</dd>
        </div>
        <div className="flex justify-between gap-2 sm:flex-col sm:justify-start">
          <dt className="text-[var(--text-muted)]">Online orders</dt>
          <dd>{account.online_payments_enabled ? "Accepted" : "Paused"}</dd>
        </div>
      </dl>
      {account.disabled_reason ? (
        <p className="mt-3 text-sm text-amber-800">Restriction: {account.disabled_reason}</p>
      ) : null}
      {account.requirements_currently_due && account.requirements_currently_due.length > 0 ? (
        <div className="mt-3 rounded-md bg-[var(--surface-muted)] p-3 text-xs">
          <p className="font-medium">Information needed</p>
          <ul className="mt-1 list-disc pl-4">
            {account.requirements_currently_due.map((item) => (
              <li key={item}>{item.replace(/_/g, " ")}</li>
            ))}
          </ul>
        </div>
      ) : null}
      {account.last_synced_at ? (
        <p className="mt-3 text-xs text-[var(--text-muted)]">
          Last synced {new Date(account.last_synced_at).toLocaleString()}
        </p>
      ) : null}
    </section>
  );
}
