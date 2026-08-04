"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { StatCard } from "@/components/marketplace/cards";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Field, Select } from "@/components/ui/forms";
import {
  adminReportApi,
  reportErrorMessage,
  type ReportRange,
} from "@/features/reporting/api/report-api";
import { ApiError } from "@/lib/api/client";
import { formatCents } from "@/lib/utils";

const RANGE_OPTIONS: Array<{ value: ReportRange; label: string }> = [
  { value: "today", label: "Today" },
  { value: "last_7_days", label: "Last 7 days" },
  { value: "last_30_days", label: "Last 30 days" },
  { value: "this_month", label: "This month" },
  { value: "previous_month", label: "Previous month" },
];

type Props = {
  businessPublicId: string;
  branchPublicId: string;
  restaurantName: string;
};

/**
 * Super-admin operational oversight panel.
 * Uses admin report routes only — does not set partner tenant localStorage.
 */
export function AdminBranchOversightPanel({
  businessPublicId,
  branchPublicId,
  restaurantName,
}: Props) {
  const [range, setRange] = useState<ReportRange>("last_30_days");

  const reportQuery = useQuery({
    queryKey: ["admin-branch-report", businessPublicId, branchPublicId, range],
    queryFn: async () =>
      (
        await adminReportApi.branchSummary(businessPublicId, branchPublicId, {
          range,
        })
      ).data,
  });

  return (
    <section className="rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
            Super Admin View
          </p>
          <h2 className="text-base font-semibold">Operational overview</h2>
          <p className="text-sm text-[var(--text-muted)]">
            Read-only inspection for {restaurantName}. You are not logged in as the partner.
          </p>
        </div>
        <Field label="Date range">
          <Select
            value={range}
            onChange={(e) => setRange(e.target.value as ReportRange)}
          >
            {RANGE_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      {reportQuery.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : reportQuery.isError ? (
        <EmptyState
          title="Unable to load oversight report"
          description={
            reportQuery.error instanceof ApiError
              ? reportErrorMessage(reportQuery.error)
              : "Request failed"
          }
        />
      ) : reportQuery.data ? (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Orders"
              value={String(reportQuery.data.summary.total_orders)}
            />
            <StatCard
              label="Completed"
              value={String(reportQuery.data.summary.completed_orders)}
            />
            <StatCard
              label="Gross order value"
              value={formatCents(reportQuery.data.summary.gross_order_value_cents)}
            />
            <StatCard
              label="Low / out of stock"
              value={`${reportQuery.data.summary.low_stock_count} / ${reportQuery.data.summary.out_of_stock_count}`}
            />
          </div>

          {reportQuery.data.configuration ? (
            <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-[var(--text-muted)]">Linked restaurant</dt>
                <dd>{reportQuery.data.configuration.linked_restaurant_slug ?? "—"}</dd>
              </div>
              <div>
                <dt className="text-[var(--text-muted)]">Timezone</dt>
                <dd>{reportQuery.data.configuration.timezone ?? reportQuery.data.meta.timezone}</dd>
              </div>
              <div>
                <dt className="text-[var(--text-muted)]">Coordinates</dt>
                <dd>{reportQuery.data.configuration.has_coordinates ? "Configured" : "Missing"}</dd>
              </div>
              <div>
                <dt className="text-[var(--text-muted)]">Pickup / delivery</dt>
                <dd>
                  {reportQuery.data.configuration.pickup_enabled ? "Pickup" : "No pickup"}
                  {" · "}
                  {reportQuery.data.configuration.delivery_enabled ? "Delivery" : "No delivery"}
                </dd>
              </div>
            </dl>
          ) : null}

          {(reportQuery.data.order_status_breakdown?.length ?? 0) > 0 ? (
            <div className="mt-4 overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="text-left text-[var(--text-muted)]">
                  <tr>
                    <th className="py-1 pr-4">Order status</th>
                    <th className="py-1">Count</th>
                  </tr>
                </thead>
                <tbody>
                  {reportQuery.data.order_status_breakdown.map((row) => (
                    <tr key={row.status} className="border-t border-[var(--border-subtle)]">
                      <td className="py-1 pr-4">{row.status}</td>
                      <td className="py-1">{row.count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      ) : null}
    </section>
  );
}
