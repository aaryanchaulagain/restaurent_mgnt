"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Field, Select } from "@/components/ui/forms";
import { useAuth } from "@/features/auth/hooks/use-auth";
import {
  getBusinessContextPublicId,
  getBranchContextPublicId,
  isAggregateBranchContext,
  readBranchDashboardContext,
} from "@/features/business/lib/branch-context";
import { businessBranchApi } from "@/features/business/api/business-branch-api";
import {
  hasEffectivePermission,
  useBranchAuthorization,
} from "@/features/restaurant/hooks/use-branch-authorization";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import {
  partnerReportApi,
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

function useTenantKey(): string {
  const [key, setKey] = useState("ssr");

  useEffect(() => {
    const read = () => {
      const ctx = readBranchDashboardContext();
      setKey(
        [
          ctx.businessPublicId ?? "nobiz",
          ctx.aggregate ? "aggregate" : (ctx.branchPublicId ?? "nobranch"),
        ].join(":"),
      );
    };
    read();
    window.addEventListener("khana-branch-context-changed", read);
    window.addEventListener("storage", read);
    return () => {
      window.removeEventListener("khana-branch-context-changed", read);
      window.removeEventListener("storage", read);
    };
  }, []);

  return key;
}

function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return "—";
  if (seconds < 60) return `${seconds}s`;
  const mins = Math.round(seconds / 60);
  return `${mins}m`;
}

export default function RestaurantReportsPage() {
  const { user } = useAuth();
  const tenantKey = useTenantKey();
  const { brand, portalLabel, items, permissions } = useRestaurantShell();
  const authz = useBranchAuthorization();
  const [range, setRange] = useState<ReportRange>("last_30_days");
  const [branchFilter, setBranchFilter] = useState<string>("auto");

  const canBusinessReports = hasEffectivePermission(permissions, [
    "view_business_reports",
  ]);
  const canBranchReports = hasEffectivePermission(permissions, [
    "view_branch_reports",
    "view_business_reports",
  ]);
  const canFinance = hasEffectivePermission(permissions, [
    "view_business_finance",
    "view_finance",
    "view_branch_finance_summary",
  ]);

  const businessPublicId =
    authz.data?.business.public_id ?? getBusinessContextPublicId();
  const contextBranchId = getBranchContextPublicId();
  const aggregate = isAggregateBranchContext();

  const contextQuery = useQuery({
    queryKey: ["business-context", user?.id ?? "anon"],
    queryFn: async () => (await businessBranchApi.context()).data,
    enabled: Boolean(user?.id) && canBusinessReports,
  });

  const effectiveBranchId = useMemo(() => {
    if (!canBusinessReports) {
      return contextBranchId ?? authz.data?.branch.public_id ?? null;
    }
    if (branchFilter === "all") return null;
    if (branchFilter !== "auto") return branchFilter;
    if (aggregate) return null;
    return contextBranchId ?? authz.data?.branch.public_id ?? null;
  }, [
    canBusinessReports,
    branchFilter,
    aggregate,
    contextBranchId,
    authz.data?.branch.public_id,
  ]);

  const reportQuery = useQuery({
    queryKey: [
      effectiveBranchId ? "branch-report" : "business-report",
      user?.id ?? "anon",
      businessPublicId ?? "none",
      effectiveBranchId ?? "all",
      range,
      tenantKey,
    ],
    enabled: Boolean(user?.id) && Boolean(businessPublicId) && canBranchReports && tenantKey !== "ssr",
    queryFn: async () => {
      if (!businessPublicId) throw new Error("Business required");
      if (effectiveBranchId) {
        return (
          await partnerReportApi.branchSummary(businessPublicId, effectiveBranchId, {
            range,
          })
        ).data;
      }
      return (await partnerReportApi.businessSummary(businessPublicId, { range })).data;
    },
    placeholderData: undefined,
  });

  const summary =
    reportQuery.data && "summary" in reportQuery.data
      ? reportQuery.data.summary
      : null;
  const branches =
    reportQuery.data && "branches" in reportQuery.data
      ? reportQuery.data.branches
      : reportQuery.data && "metrics" in reportQuery.data && reportQuery.data.metrics
        ? [reportQuery.data.metrics]
        : [];
  const statusBreakdown = reportQuery.data?.order_status_breakdown ?? [];
  const paymentBreakdown = canFinance ? reportQuery.data?.payment_breakdown : null;
  const meta = reportQuery.data?.meta;

  const authorizedBranches = useMemo(() => {
    if (!canBusinessReports) return [];
    const all = contextQuery.data?.branches ?? [];
    if (!businessPublicId) return all;
    return all.filter(
      (b) =>
        b.business_public_id === businessPublicId ||
        String(b.business_id) === String(contextQuery.data?.businesses.find((x) => x.public_id === businessPublicId)?.id),
    );
  }, [canBusinessReports, contextQuery.data, businessPublicId]);

  if (!canBranchReports && permissions !== null) {
    return (
      <AdminShell brand={brand} portalLabel={portalLabel} items={items} title="Reports">
        <EmptyState
          title="Access denied"
          description="You do not have permission to view operational reports."
        />
      </AdminShell>
    );
  }

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={items}
      title="Reports"
      subtitle="Read-only operational summaries for authorized branches"
    >
      <div className="mb-6 flex flex-wrap items-end gap-4">
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

        {canBusinessReports ? (
          <Field label="Branch">
            <Select
              value={branchFilter}
              onChange={(e) => setBranchFilter(e.target.value)}
            >
              <option value="auto">Current context</option>
              <option value="all">All branches</option>
              {authorizedBranches.map((b) => (
                <option key={b.public_id} value={b.public_id}>
                  {b.name}
                </option>
              ))}
            </Select>
          </Field>
        ) : (
          <p className="text-sm text-[var(--text-muted)]">
            Showing assigned branch only
            {authz.data?.branch.name ? `: ${authz.data.branch.name}` : ""}
          </p>
        )}
      </div>

      {meta ? (
        <p className="mb-4 text-xs text-[var(--text-muted)]">
          Timezone {meta.timezone} · {meta.start_at.slice(0, 10)} → {meta.end_at.slice(0, 10)}
        </p>
      ) : null}

      {reportQuery.isLoading || reportQuery.isFetching ? (
        <Skeleton className="h-48 w-full" />
      ) : reportQuery.isError ? (
        <EmptyState
          title="Unable to load report"
          description={
            reportQuery.error instanceof ApiError
              ? reportErrorMessage(reportQuery.error)
              : "Request failed"
          }
        />
      ) : !summary ? (
        <EmptyState title="No report data" description="Try another date range." />
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Total orders" value={String(summary.total_orders)} />
            <StatCard label="Completed" value={String(summary.completed_orders)} />
            <StatCard
              label="Gross order value"
              value={formatCents(summary.gross_order_value_cents)}
              hint="From order snapshots — not partner payout"
            />
            <StatCard
              label="Average order value"
              value={formatCents(summary.average_order_value_cents)}
            />
            <StatCard label="Active / pending" value={String(summary.active_orders)} />
            <StatCard
              label="Cancelled / rejected / expired"
              value={`${summary.cancelled_orders} / ${summary.rejected_orders} / ${summary.expired_orders}`}
            />
            <StatCard label="Low stock" value={String(summary.low_stock_count)} />
            <StatCard label="Out of stock" value={String(summary.out_of_stock_count)} />
            {canFinance && summary.paid_amount_cents !== null ? (
              <>
                <StatCard
                  label="Paid amount"
                  value={formatCents(summary.paid_amount_cents)}
                  hint="From payment records"
                />
                <StatCard
                  label="Refunded amount"
                  value={formatCents(summary.refunded_amount_cents ?? 0)}
                />
              </>
            ) : null}
          </div>

          {branches.length > 0 ? (
            <div className="mt-8 overflow-x-auto rounded-lg border bg-white">
              <table className="min-w-full text-sm">
                <thead className="bg-[var(--surface-muted)] text-left">
                  <tr>
                    <th className="p-3">Branch</th>
                    <th className="p-3">Status</th>
                    <th className="p-3">Orders</th>
                    <th className="p-3">Completed</th>
                    <th className="p-3">Gross</th>
                    <th className="p-3">AOV</th>
                    <th className="p-3">Low / Out</th>
                    <th className="p-3">Avg accept</th>
                    <th className="p-3">Avg prep</th>
                  </tr>
                </thead>
                <tbody>
                  {branches.map((b) => (
                    <tr key={b.public_id} className="border-t">
                      <td className="p-3 font-medium">{b.name}</td>
                      <td className="p-3">{b.status}</td>
                      <td className="p-3">{b.total_orders}</td>
                      <td className="p-3">{b.completed_orders}</td>
                      <td className="p-3">{formatCents(b.gross_order_value_cents)}</td>
                      <td className="p-3">{formatCents(b.average_order_value_cents)}</td>
                      <td className="p-3">
                        {b.low_stock_count} / {b.out_of_stock_count}
                      </td>
                      <td className="p-3">
                        {formatDuration(b.average_acceptance_seconds)}
                      </td>
                      <td className="p-3">
                        {formatDuration(b.average_preparation_seconds)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}

          {statusBreakdown.length > 0 ? (
            <div className="mt-8">
              <h2 className="mb-3 text-base font-semibold">Order status breakdown</h2>
              <div className="overflow-x-auto rounded-lg border bg-white">
                <table className="min-w-full text-sm">
                  <thead className="bg-[var(--surface-muted)] text-left">
                    <tr>
                      <th className="p-3">Status</th>
                      <th className="p-3">Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    {statusBreakdown.map((row) => (
                      <tr key={row.status} className="border-t">
                        <td className="p-3">{row.status}</td>
                        <td className="p-3">{row.count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}

          {paymentBreakdown ? (
            <div className="mt-8">
              <h2 className="mb-3 text-base font-semibold">Payment summary</h2>
              <div className="mb-3 grid gap-4 sm:grid-cols-2">
                <StatCard
                  label="Paid amount"
                  value={formatCents(paymentBreakdown.paid_amount_cents ?? 0)}
                />
                <StatCard
                  label="Refunded amount"
                  value={formatCents(paymentBreakdown.refunded_amount_cents ?? 0)}
                />
              </div>
              {(paymentBreakdown.by_status?.length ?? 0) > 0 ? (
                <div className="overflow-x-auto rounded-lg border bg-white">
                  <table className="min-w-full text-sm">
                    <thead className="bg-[var(--surface-muted)] text-left">
                      <tr>
                        <th className="p-3">Status</th>
                        <th className="p-3">Count</th>
                      </tr>
                    </thead>
                    <tbody>
                      {paymentBreakdown.by_status.map((row) => (
                        <tr key={row.status} className="border-t">
                          <td className="p-3">{row.status}</td>
                          <td className="p-3">{row.count}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
            </div>
          ) : null}
        </>
      )}
    </AdminShell>
  );
}
