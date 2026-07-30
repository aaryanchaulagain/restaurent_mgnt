"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Field, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminDisputeApi } from "@/features/payments/api/payment-api";

export default function AdminDisputesPage() {
  const [status, setStatus] = useState("");

  const disputes = useQuery({
    queryKey: ["admin-disputes", status],
    queryFn: async () =>
      (await adminDisputeApi.list({ status: status || undefined })).data.disputes,
  });

  const rows = disputes.data ?? [];

  return (
    <AdminShell brand="Suvakamana" portalLabel="Super Admin" items={adminNav} title="Disputes" subtitle="Payment disputes from Stripe">
      <Field label="Status" className="mb-4 max-w-xs">
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="needs_response">Needs response</option>
          <option value="under_review">Under review</option>
          <option value="won">Won</option>
          <option value="lost">Lost</option>
        </Select>
      </Field>
      {disputes.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <EmptyState title="No disputes" description="Disputes will appear when chargebacks are opened." />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Dispute</th>
                <th className="p-3">Amount</th>
                <th className="p-3">Status</th>
                <th className="p-3">Reason</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((d) => (
                <tr key={String(d.public_id)} className="border-t">
                  <td className="p-3 font-mono text-xs">{String(d.public_id).slice(0, 8)}…</td>
                  <td className="p-3">{formatCents(Number(d.amount_cents ?? 0))}</td>
                  <td className="p-3">{String(d.status)}</td>
                  <td className="p-3">{String(d.reason ?? "—")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AdminShell>
  );
}
