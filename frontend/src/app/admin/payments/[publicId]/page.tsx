"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminPaymentApi } from "@/features/payments/api/payment-api";
import { ownershipLabel } from "@/features/payments/utils/ownership-label";
import { RefundRequestModal } from "@/features/payments/components/RefundRequestModal";
import { ApiError } from "@/lib/api/client";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

export default function AdminPaymentDetailPage({ params }: { params: Promise<{ publicId: string }> }) {
  const { publicId } = use(params);
  const qc = useQueryClient();
  const [refundOpen, setRefundOpen] = useState(false);
  const [refundError, setRefundError] = useState<string | null>(null);

  const detail = useQuery({
    queryKey: ["admin-payment", publicId],
    queryFn: async () => (await adminPaymentApi.get(publicId)).data,
  });

  const refund = useMutation({
    mutationFn: (body: Parameters<typeof adminPaymentApi.createRefund>[1]) =>
      adminPaymentApi.createRefund(publicId, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-payment", publicId] });
      setRefundOpen(false);
      setRefundError(null);
    },
    onError: (e: unknown) => {
      setRefundError(e instanceof ApiError ? paymentErrorMessage(e.code, e.message) : "Refund failed.");
    },
  });

  const p = detail.data?.payment;
  const received = p ? (p.amount_received_cents ?? p.amount_cents) : 0;
  const refunded = p?.amount_refunded_cents ?? 0;
  const maxRefund = Math.max(0, received - refunded);

  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Payment detail"
      subtitle={p?.public_id ?? publicId}
      actions={
        p && maxRefund > 0 ? (
          <Button onClick={() => setRefundOpen(true)}>Issue refund</Button>
        ) : (
          <Link href="/admin/payments">
            <Button variant="outline">Back to list</Button>
          </Link>
        )
      }
    >
      {detail.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : !p ? (
        <p className="text-sm text-[var(--text-muted)]">Payment not found.</p>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <section className="rounded-lg border bg-white p-5">
            <h2 className="text-xl font-semibold">Summary</h2>
            <dl className="mt-4 space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Status</dt>
                <dd>
                  <Badge tone={p.status === "paid" ? "success" : "info"}>{p.status}</Badge>
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Amount</dt>
                <dd>{formatCents(p.amount_cents)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Received</dt>
                <dd>{formatCents(p.amount_received_cents ?? 0)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Refunded</dt>
                <dd>{formatCents(p.amount_refunded_cents ?? 0)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Order</dt>
                <dd>{p.order_number ?? p.order_public_id}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-[var(--text-muted)]">Restaurant</dt>
                <dd>
                  {p.restaurant_name} · {ownershipLabel(p.ownership_type)}
                </dd>
              </div>
              {p.external_payment_intent_id ? (
                <div className="flex justify-between">
                  <dt className="text-[var(--text-muted)]">Payment intent</dt>
                  <dd className="font-mono text-xs">{p.external_payment_intent_id}</dd>
                </div>
              ) : null}
            </dl>
          </section>

          <section className="rounded-lg border bg-white p-5">
            <h2 className="text-xl font-semibold">Refunds</h2>
            {p.refunds && p.refunds.length > 0 ? (
              <ul className="mt-3 space-y-2 text-sm">
                {p.refunds.map((r) => (
                  <li key={r.public_id} className="flex justify-between border-b pb-2">
                    <span>
                      {formatCents(r.amount_cents)} · {r.reason_category}
                    </span>
                    <Badge tone="info">{r.status}</Badge>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-3 text-sm text-[var(--text-muted)]">No refunds yet.</p>
            )}
          </section>
        </div>
      )}

      {refundError ? <p className="mt-4 text-sm text-red-600">{refundError}</p> : null}

      {p ? (
        <RefundRequestModal
          open={refundOpen}
          onClose={() => setRefundOpen(false)}
          maxAmountCents={maxRefund}
          submitting={refund.isPending}
          onSubmit={async (body) => {
            await refund.mutateAsync(body);
          }}
        />
      ) : null}
    </AdminShell>
  );
}
