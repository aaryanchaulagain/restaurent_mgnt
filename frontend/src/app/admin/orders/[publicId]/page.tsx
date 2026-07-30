"use client";

import Link from "next/link";
import { use } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { adminOrderApi, ownershipLabel } from "@/features/orders/api/admin-order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { OrderStatusTimeline } from "@/features/orders/components/order-status-timeline";

export default function AdminOrderDetailPage({ params }: { params: Promise<{ publicId: string }> }) {
  const { publicId } = use(params);
  const detail = useQuery({
    queryKey: ["admin-order", publicId],
    queryFn: async () => (await adminOrderApi.get(publicId)).data,
  });

  const o = detail.data?.order;

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title={o ? `Order ${o.order_number}` : "Order"}
      actions={<Link href="/admin/orders"><Button variant="outline">Back</Button></Link>}
    >
      {detail.isLoading || !o ? <Skeleton className="h-96 w-full" /> : (
        <div className="grid gap-6 lg:grid-cols-2">
          <section className="rounded-lg border bg-white p-5 space-y-3">
            <div className="flex justify-between"><OrderStatusBadge status={o.status} /><span>{formatCents(o.total_cents)}</span></div>
            <p className="text-sm">{o.restaurant?.trading_name} · {ownershipLabel(o.restaurant?.ownership_type)}</p>
            <p className="text-sm">{o.customer_name} · {o.customer_email}</p>
            <p className="text-sm">Commission snapshot: {o.commission_rate_snapshot ?? 0} ({formatCents(o.commission_amount_cents ?? 0)})</p>
            <p className="text-xs text-[var(--text-muted)]">Idempotency: recorded (details hidden for security)</p>
          </section>
          <section className="rounded-lg border bg-white p-5">
            <h2 className="font-semibold">Timeline</h2>
            <div className="mt-3"><OrderStatusTimeline timeline={o.timeline ?? []} /></div>
          </section>
        </div>
      )}
    </AdminShell>
  );
}
