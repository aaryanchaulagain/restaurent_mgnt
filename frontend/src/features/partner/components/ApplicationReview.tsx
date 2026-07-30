"use client";

import type { RestaurantApplication } from "../types";
import { displayApplicationName, primaryAddress } from "../utils/status";

export function ApplicationReview({ application }: { application: RestaurantApplication }) {
  const address = primaryAddress(application);

  return (
    <dl className="space-y-3 text-sm">
      <ReviewRow label="Trading name" value={displayApplicationName(application)} />
      <ReviewRow label="Legal name" value={application.legal_business_name} />
      <ReviewRow label="ABN" value={application.abn} />
      <ReviewRow label="Cuisine" value={application.cuisine_summary} />
      <ReviewRow label="Contact" value={application.primary_contact_name} />
      <ReviewRow label="Contact email" value={application.primary_contact_email} />
      <ReviewRow label="Contact phone" value={application.primary_contact_phone} />
      <ReviewRow label="Service type" value={application.service_type?.replace(/_/g, " ")} />
      <ReviewRow
        label="Address"
        value={
          address
            ? `${address.address_line_1}, ${address.suburb} ${address.state} ${address.postcode}`
            : null
        }
      />
      <ReviewRow label="Documents uploaded" value={String(application.documents?.length ?? 0)} />
    </dl>
  );
}

function ReviewRow({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex justify-between gap-4 border-b border-[var(--border-subtle)] pb-2">
      <dt className="text-[var(--text-muted)]">{label}</dt>
      <dd className="text-right font-medium">{value || "—"}</dd>
    </div>
  );
}
