"use client";

import Link from "next/link";
import { Badge } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import type { RestaurantApplication } from "../types";
import { displayApplicationName, statusBadgeTone, statusLabel } from "../utils/status";

export function AdminApplicationTable({
  applications,
  loading,
}: {
  applications: RestaurantApplication[];
  loading?: boolean;
}) {
  if (loading) {
    return <p className="text-sm text-[var(--text-secondary)]">Loading applications…</p>;
  }

  if (!applications.length) {
    return (
      <p className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-8 text-center text-sm text-[var(--text-secondary)]">
        No applications match your filters.
      </p>
    );
  }

  return (
    <div className="grid gap-4">
      {applications.map((app) => (
        <article
          key={app.public_id}
          className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]"
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <Link
                href={`/admin/applications/${app.public_id}`}
                className="text-2xl font-[family-name:var(--font-display)] hover:text-[var(--color-burnt-orange)]"
              >
                {displayApplicationName(app)}
              </Link>
              <p className="mt-1 text-sm text-[var(--text-secondary)]">
                {app.applicant?.name ?? "Applicant"} · {app.applicant?.email ?? "—"} ·{" "}
                {app.cuisine_summary ?? "—"}
                {app.submitted_at
                  ? ` · Submitted ${new Date(app.submitted_at).toLocaleDateString()}`
                  : ""}
              </p>
            </div>
            <Badge tone={statusBadgeTone(app.status)}>{statusLabel(app.status)}</Badge>
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            <Link href={`/admin/applications/${app.public_id}`}>
              <Button size="sm">Review</Button>
            </Link>
          </div>
        </article>
      ))}
    </div>
  );
}
