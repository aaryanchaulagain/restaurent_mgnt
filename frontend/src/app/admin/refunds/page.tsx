"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Field, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminRefundApi } from "@/features/payments/api/payment-api";

export default function AdminRefundsPage() {
  const [status, setStatus] = useState("");

  const refunds = useQuery({
    queryKey: ["admin-refunds", status],
    queryFn: async () =>
      (await adminRefundApi.list({ status: status || undefined })).data.refunds,
  });

  const rows = refunds.data ?? [];

  return (
    <AdminShell brand="Khana" portalLabel="Super Admin" items={adminNav} title="Refunds" subtitle="Platform refund requests">
      <Field label="Status" className="mb-4 max-w-xs">
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="completed">Completed</option>
          <option value="failed">Failed</option>
        </Select>
      </Field>
      {refunds.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <EmptyState title="No refunds" description="Refunds will appear when issued from the payments console." />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Refund</th>
                <th className="p-3">Order</th>
                <th className="p-3">Amount</th>
                <th className="p-3">Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={String(r.public_id)} className="border-t">
                  <td className="p-3 font-mono text-xs">{String(r.public_id).slice(0, 8)}…</td>
                  <td className="p-3">{String(r.order_number ?? "—")}</td>
                  <td className="p-3">{formatCents(Number(r.amount_cents ?? 0))}</td>
                  <td className="p-3">{String(r.status)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AdminShell>
  );
}
