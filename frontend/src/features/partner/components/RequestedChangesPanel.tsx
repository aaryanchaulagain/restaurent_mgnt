"use client";

import type { RestaurantApplication } from "../types";

export function RequestedChangesPanel({ application }: { application: RestaurantApplication }) {
  if (application.status !== "changes_requested") return null;

  return (
    <section className="rounded-[var(--radius-lg)] border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/5 p-5">
      <h2 className="text-xl font-[family-name:var(--font-display)]">Changes requested</h2>
      {application.changes_requested_reason ? (
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          {application.changes_requested_reason}
        </p>
      ) : null}
      {application.changes_requested_items?.length ? (
        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
          {application.changes_requested_items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
