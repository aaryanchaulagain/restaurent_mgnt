"use client";

import Link from "next/link";
import { use } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { useToast } from "@/components/ui/navigation";
import { restaurantOrderApi } from "@/features/orders/api/order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { OrderStatusTimeline } from "@/features/orders/components/order-status-timeline";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { formatCents } from "@/lib/utils";

export default function RestaurantOrderDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { brand, portalLabel, items: navItems } = useRestaurantShell();
  const qc = useQueryClient();
  const { push } = useToast();

  const order = useQuery({
    queryKey: ["restaurant-order", id],
    queryFn: async () => (await restaurantOrderApi.get(id)).data.order,
    refetchInterval: 12_000,
    refetchIntervalInBackground: false,
  });

  const acceptMut = useMutation({
    mutationFn: () => restaurantOrderApi.accept(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-order", id] }); push({ title: "Order accepted", tone: "success" }); },
  });
  const prepareMut = useMutation({
    mutationFn: () => restaurantOrderApi.startPreparing(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-order", id] }); push({ title: "Preparation started", tone: "success" }); },
  });
  const readyMut = useMutation({
    mutationFn: () => restaurantOrderApi.markReady(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-order", id] }); push({ title: "Marked ready", tone: "success" }); },
  });
  const completeMut = useMutation({
    mutationFn: () => restaurantOrderApi.completePickup(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-order", id] }); push({ title: "Completed", tone: "success" }); },
  });

  const o = order.data;

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={navItems}
      title={o ? `Order ${o.order_number}` : "Order"}
      subtitle={o ? `${o.fulfilment_type} · ${o.payment_method}` : ""}
      actions={<Link href="/restaurant/orders"><Button variant="outline">Back to orders</Button></Link>}
    >
      {order.isLoading || !o ? <Skeleton className="h-96 w-full" /> : (
        <div className="grid gap-6 lg:grid-cols-2">
          <section className="space-y-4 rounded-lg border bg-white p-5">
            <div className="flex items-center justify-between">
              <h2 className="text-xl font-semibold">Customer</h2>
              <OrderStatusBadge status={o.status} />
            </div>
            <dl className="space-y-2 text-sm">
              {o.customer_name ? <div className="flex justify-between"><dt className="text-[var(--text-muted)]">Name</dt><dd>{o.customer_name}</dd></div> : null}
              {o.customer_email ? <div className="flex justify-between"><dt className="text-[var(--text-muted)]">Email</dt><dd>{o.customer_email}</dd></div> : null}
              {o.customer_phone ? <div className="flex justify-between"><dt className="text-[var(--text-muted)]">Phone</dt><dd>{o.customer_phone}</dd></div> : null}
              {o.placed_at ? <div className="flex justify-between"><dt className="text-[var(--text-muted)]">Placed</dt><dd>{new Date(o.placed_at).toLocaleString()}</dd></div> : null}
              <div className="flex justify-between"><dt className="text-[var(--text-muted)]">Payment</dt><dd>{o.payment_method} · {o.payment_status}</dd></div>
            </dl>
            {o.customer_notes ? <p className="text-sm text-[var(--text-secondary)]">Note: {o.customer_notes}</p> : null}
            {o.pickup_instructions ? <p className="text-sm text-[var(--text-secondary)]">Pickup: {o.pickup_instructions}</p> : null}

            <div className="flex flex-wrap gap-2 pt-2">
              {o.status === "awaiting_restaurant" ? <Button size="sm" onClick={() => acceptMut.mutate()} loading={acceptMut.isPending}>Accept</Button> : null}
              {o.status === "accepted" ? <Button size="sm" onClick={() => prepareMut.mutate()} loading={prepareMut.isPending}>Start Preparing</Button> : null}
              {o.status === "preparing" ? <Button size="sm" onClick={() => readyMut.mutate()} loading={readyMut.isPending}>Mark Ready</Button> : null}
              {o.status === "ready_for_pickup" ? <Button size="sm" onClick={() => completeMut.mutate()} loading={completeMut.isPending}>Complete Pickup</Button> : null}
            </div>
          </section>

          <section className="space-y-4 rounded-lg border bg-white p-5">
            <h2 className="text-xl font-semibold">Items</h2>
            <ul className="space-y-3 text-sm">
              {o.items.map((item) => (
                <li key={item.public_id}>
                  <div className="flex justify-between">
                    <span>{item.quantity}× {item.name}{item.variant ? ` (${item.variant})` : ""}</span>
                    <span>{formatCents(item.line_total_cents)}</span>
                  </div>
                  {item.modifiers.length > 0 ? (
                    <ul className="ml-4 text-xs text-[var(--text-muted)]">
                      {item.modifiers.map((m, i) => <li key={i}>+ {m.option} ({formatCents(m.price_adjustment_cents)})</li>)}
                    </ul>
                  ) : null}
                  {item.instructions ? <p className="ml-4 text-xs italic text-[var(--text-secondary)]">{item.instructions}</p> : null}
                </li>
              ))}
            </ul>
            <hr />
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt>Subtotal</dt><dd>{formatCents(o.subtotal_cents)}</dd></div>
              {o.discount_cents > 0 ? <div className="flex justify-between text-green-700"><dt>Discount</dt><dd>-{formatCents(o.discount_cents)}</dd></div> : null}
              {o.tax_cents > 0 ? <div className="flex justify-between"><dt>Tax</dt><dd>{formatCents(o.tax_cents)}</dd></div> : null}
              {o.service_fee_cents > 0 ? <div className="flex justify-between"><dt>Service fee</dt><dd>{formatCents(o.service_fee_cents)}</dd></div> : null}
              <div className="flex justify-between font-semibold"><dt>Total</dt><dd>{formatCents(o.total_cents)}</dd></div>
            </dl>
          </section>

          {o.timeline.length > 0 ? (
            <section className="rounded-lg border bg-white p-5 lg:col-span-2">
              <h2 className="mb-4 text-xl font-semibold">Status Timeline</h2>
              <OrderStatusTimeline timeline={o.timeline} />
            </section>
          ) : null}
        </div>
      )}
    </AdminShell>
  );
}
