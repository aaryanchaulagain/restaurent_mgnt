"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminPaymentApi } from "@/features/payments/api/payment-api";
import { ownershipLabel } from "@/features/payments/utils/ownership-label";

function statusTone(status: string): "success" | "warning" | "error" | "info" {
  if (status === "paid") return "success";
  if (status === "failed" || status === "cancelled") return "error";
  if (status === "pending" || status === "processing" || status === "requires_action") return "warning";
  return "info";
}

export default function AdminPaymentsPage() {
  const [status, setStatus] = useState("");
  const [provider, setProvider] = useState("");
  const [orderPublicId, setOrderPublicId] = useState("");
  const [page, setPage] = useState(1);

  const params = useMemo(
    () => ({
      status: status || undefined,
      provider: provider || undefined,
      order_public_id: orderPublicId || undefined,
      page,
    }),
    [status, provider, orderPublicId, page],
  );

  const payments = useQuery({
    queryKey: ["admin-payments", params],
    queryFn: async () => {
      const res = await adminPaymentApi.list(params);
      return { payments: res.data.payments, meta: res.meta };
    },
  });

  const rows = payments.data?.payments ?? [];
  const lastPage = (payments.data?.meta?.last_page as number | undefined) ?? 1;

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Payments"
      subtitle="Live platform payment activity"
    >
      <div className="mb-6 grid gap-4 rounded-lg border bg-white p-4 sm:grid-cols-3">
        <Field label="Status">
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">All</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="failed">Failed</option>
            <option value="refunded">Refunded</option>
          </Select>
        </Field>
        <Field label="Provider">
          <Select value={provider} onChange={(e) => { setProvider(e.target.value); setPage(1); }}>
            <option value="">All</option>
            <option value="stripe">Stripe</option>
          </Select>
        </Field>
        <Field label="Order public ID">
          <Input value={orderPublicId} onChange={(e) => { setOrderPublicId(e.target.value); setPage(1); }} placeholder="UUID" />
        </Field>
      </div>

      {payments.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.length === 0 ? (
        <EmptyState title="No payments" description="Adjust filters or wait for new card payments." />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Payment</th>
                <th className="p-3">Order</th>
                <th className="p-3">Restaurant</th>
                <th className="p-3">Amount</th>
                <th className="p-3">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {rows.map((p) => (
                <tr key={p.public_id} className="border-t">
                  <td className="p-3 font-mono text-xs">{p.public_id.slice(0, 8)}…</td>
                  <td className="p-3">{p.order_number ?? "—"}</td>
                  <td className="p-3">
                    <p>{p.restaurant_name ?? "—"}</p>
                    <p className="text-xs text-[var(--text-muted)]">{ownershipLabel(p.ownership_type)}</p>
                  </td>
                  <td className="p-3">{formatCents(p.amount_cents)}</td>
                  <td className="p-3">
                    <Badge tone={statusTone(p.status)}>{p.status.replace(/_/g, " ")}</Badge>
                  </td>
                  <td className="p-3">
                    <Link href={`/admin/payments/${p.public_id}`}>
                      <Button size="sm" variant="outline">
                        View
                      </Button>
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {lastPage > 1 ? (
        <div className="mt-4 flex justify-center gap-2">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
            Next
          </Button>
        </div>
      ) : null}
    </AdminShell>
  );
}
