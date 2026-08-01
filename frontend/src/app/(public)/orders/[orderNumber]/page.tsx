"use client";

import Link from "next/link";
import { use, useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { Badge, EmptyState } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Breadcrumbs } from "@/components/ui/navigation";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { OrderStatusTimeline } from "@/features/orders/components/order-status-timeline";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { formatCents } from "@/lib/utils";

export default function OrderTrackingPage({ params }: { params: Promise<{ orderNumber: string }> }) {
  const { orderNumber } = use(params);
  const { user } = useAuth();
  const guestToken = useMemo(() => {
    if (typeof window !== "undefined") {
      return sessionStorage.getItem(`order_token_${orderNumber}`) ?? null;
    }
    return null;
  }, [orderNumber]);

  const isGuest = !user;

  const orderQuery = useQuery({
    queryKey: ["order-track", orderNumber, isGuest],
    queryFn: async () => {
      if (user) {
        const res = await customerOrderApi.list();
        const found = res.data.orders.find((o) => o.order_number === orderNumber);
        if (!found) throw new Error("Not found");
        const detail = await customerOrderApi.get(found.public_id);
        return detail.data.order;
      }
      if (guestToken) {
        const res = await customerOrderApi.guestTrack(orderNumber, guestToken);
        return res.data.order;
      }
      return null;
    },
    enabled: Boolean(user) || Boolean(guestToken),
    refetchInterval: 12_000,
    refetchIntervalInBackground: false,
  });

  const o = orderQuery.data;

  if (!user && !guestToken) {
    return (
      <main className="mx-auto max-w-lg px-4 py-16 text-center">
        <EmptyState title="Sign in to view this order" description="Or use the guest tracking page." action={<Link href="/orders/track"><Button>Track Order</Button></Link>} />
      </main>
    );
  }

  if (orderQuery.isLoading || !o) {
    return <main className="mx-auto max-w-4xl px-4 py-8"><p className="text-center text-[var(--text-muted)]">Loading order…</p></main>;
  }

  return (
    <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
      <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Orders" }, { label: o.order_number }]} />
      <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Order {o.order_number}</h1>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">Your order has been sent to the restaurant.</p>
        </div>
        <OrderStatusBadge status={o.status} />
      </div>

      <div className="mt-8 grid gap-6 md:grid-cols-2">
        <section className="rounded-lg border bg-white p-5">
          <h2 className="text-xl font-semibold">Payment</h2>
          <dl className="mt-3 space-y-1 text-sm">
            <div className="flex justify-between"><dt>Method</dt><dd className="capitalize">{o.payment_method.replace(/_/g, " ")}</dd></div>
            <div className="flex justify-between"><dt>Status</dt><dd><Badge tone={o.payment_status === "unpaid" ? "warning" : "info"}>{o.payment_status}</Badge></dd></div>
            <div className="flex justify-between font-semibold mt-2"><dt>Total</dt><dd>{formatCents(o.total_cents)}</dd></div>
          </dl>
        </section>

        <section className="rounded-lg border bg-white p-5">
          <h2 className="text-xl font-semibold">Fulfilment</h2>
          <p className="mt-2 text-sm capitalize text-[var(--text-secondary)]">{o.fulfilment_type.replace(/_/g, " ")}</p>
          {o.pickup_instructions ? <p className="mt-1 text-sm">{o.pickup_instructions}</p> : null}
          {o.customer_notes ? <p className="mt-1 text-sm italic">{o.customer_notes}</p> : null}
        </section>
      </div>

      <section className="mt-6 rounded-lg border bg-[var(--surface-muted)] p-5">
        <h2 className="text-xl font-semibold">Order Items</h2>
        <ul className="mt-4 space-y-2 text-sm">
          {o.items.map((item) => (
            <li key={item.public_id}>
              <div className="flex justify-between">{item.quantity}× {item.name}{item.variant ? ` (${item.variant})` : ""} <span>{formatCents(item.line_total_cents)}</span></div>
              {item.modifiers.length > 0 ? <ul className="ml-4 text-xs text-[var(--text-muted)]">{item.modifiers.map((m, i) => <li key={i}>+ {m.option}</li>)}</ul> : null}
            </li>
          ))}
        </ul>
        <hr className="my-3" />
        <dl className="space-y-1 text-sm">
          <div className="flex justify-between"><dt>Subtotal</dt><dd>{formatCents(o.subtotal_cents)}</dd></div>
          {o.discount_cents > 0 ? <div className="flex justify-between text-green-700"><dt>Discount</dt><dd>-{formatCents(o.discount_cents)}</dd></div> : null}
          <div className="flex justify-between font-semibold"><dt>Total</dt><dd>{formatCents(o.total_cents)}</dd></div>
        </dl>
      </section>

      {o.timeline.length > 0 ? (
        <section className="mt-6 rounded-lg border bg-white p-5">
          <h2 className="mb-3 text-xl font-semibold">Status Timeline</h2>
          <OrderStatusTimeline timeline={o.timeline} />
        </section>
      ) : null}

      <div className="mt-8 flex gap-3">
        <Link href="/"><Button variant="outline">Return to Home</Button></Link>
        <Link href="/orders/track"><Button variant="secondary">Track Another Order</Button></Link>
      </div>
    </main>
  );
}
