"use client";

import Link from "next/link";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Select, Textarea } from "@/components/ui/forms";
import { Tabs, useToast } from "@/components/ui/navigation";
import { Modal } from "@/components/ui/overlay";
import { restaurantOrderApi, type OrderDetail } from "@/features/orders/api/order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

const tabs = [
  { id: "", label: "All" },
  { id: "awaiting_restaurant", label: "New" },
  { id: "accepted", label: "Accepted" },
  { id: "preparing", label: "Preparing" },
  { id: "ready_for_pickup", label: "Ready" },
  { id: "completed_pickup", label: "Completed" },
  { id: "rejected", label: "Rejected" },
  { id: "cancelled", label: "Cancelled" },
];

export default function RestaurantOrdersPage() {
  const profile = useRestaurantProfile();
  const qc = useQueryClient();
  const { push } = useToast();
  const [tab, setTab] = useState("");
  const [rejectTarget, setRejectTarget] = useState<OrderDetail | null>(null);
  const [rejectReason, setRejectReason] = useState("restaurant_too_busy");
  const [rejectExplanation, setRejectExplanation] = useState("");

  const orders = useQuery({
    queryKey: ["restaurant-orders", tab],
    queryFn: async () => (await restaurantOrderApi.list(tab || undefined)).data.orders,
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
  });

  const acceptMut = useMutation({
    mutationFn: (id: string) => restaurantOrderApi.accept(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-orders"] }); push({ title: "Order accepted", tone: "success" }); },
  });

  const rejectMut = useMutation({
    mutationFn: () => restaurantOrderApi.reject(rejectTarget!.public_id, rejectReason, rejectExplanation),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-orders"] }); setRejectTarget(null); push({ title: "Order rejected", tone: "error" }); },
  });

  const prepareMut = useMutation({
    mutationFn: (id: string) => restaurantOrderApi.startPreparing(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-orders"] }); push({ title: "Preparation started", tone: "success" }); },
  });

  const readyMut = useMutation({
    mutationFn: (id: string) => restaurantOrderApi.markReady(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-orders"] }); push({ title: "Marked ready", tone: "success" }); },
  });

  const completeMut = useMutation({
    mutationFn: (id: string) => restaurantOrderApi.completePickup(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["restaurant-orders"] }); push({ title: "Pickup completed", tone: "success" }); },
  });

  const newCount = (orders.data ?? []).filter((o) => o.status === "awaiting_restaurant").length;

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title={`Orders${newCount > 0 ? ` (${newCount} new)` : ""}`}
      subtitle="Accept, prepare and complete live tickets"
    >
      <Tabs tabs={tabs} value={tab} onChange={setTab} />

      {orders.isLoading ? <Skeleton className="h-64 w-full mt-6" /> : (orders.data ?? []).length === 0 ? (
        <div className="mt-6"><EmptyState title="No orders" description="Orders will appear here when customers place them." /></div>
      ) : (
        <div className="mt-6 grid gap-4">
          {(orders.data ?? []).map((order) => (
            <article key={order.public_id} className="rounded-lg border bg-white p-5 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <Link href={`/restaurant/orders/${order.public_id}`} className="font-semibold hover:text-[var(--color-burnt-orange)]">
                    {order.order_number}
                  </Link>
                  <p className="text-sm text-[var(--text-secondary)]">
                    {order.customer_name} · {order.fulfilment_type} · {order.placed_at ? new Date(order.placed_at).toLocaleTimeString() : ""}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <OrderStatusBadge status={order.status} />
                  <span className="font-semibold">{formatCents(order.total_cents)}</span>
                </div>
              </div>
              {order.items.length > 0 ? (
                <p className="mt-2 text-sm text-[var(--text-secondary)]">
                  {order.items.map((i) => `${i.quantity}× ${i.name}`).join(", ")}
                </p>
              ) : null}
              <p className="text-xs text-[var(--text-muted)] mt-1">Payment: {order.payment_method} · {order.payment_status}</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {order.status === "awaiting_restaurant" ? (
                  <>
                    <Button size="sm" onClick={() => acceptMut.mutate(order.public_id)} loading={acceptMut.isPending}>Accept</Button>
                    <Button size="sm" variant="destructive" onClick={() => { setRejectTarget(order); setRejectReason("restaurant_too_busy"); setRejectExplanation(""); }}>Reject</Button>
                  </>
                ) : order.status === "accepted" ? (
                  <Button size="sm" onClick={() => prepareMut.mutate(order.public_id)} loading={prepareMut.isPending}>Start Preparing</Button>
                ) : order.status === "preparing" ? (
                  <Button size="sm" onClick={() => readyMut.mutate(order.public_id)} loading={readyMut.isPending}>Mark Ready</Button>
                ) : order.status === "ready_for_pickup" ? (
                  <Button size="sm" onClick={() => completeMut.mutate(order.public_id)} loading={completeMut.isPending}>Complete Pickup</Button>
                ) : (
                  <Link href={`/restaurant/orders/${order.public_id}`}><Button size="sm" variant="outline">View</Button></Link>
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      <Modal open={Boolean(rejectTarget)} onClose={() => setRejectTarget(null)} title={`Reject ${rejectTarget?.order_number}?`}>
        <div className="space-y-4">
          <Field label="Reason" htmlFor="reject-reason">
            <Select id="reject-reason" value={rejectReason} onChange={(e) => setRejectReason(e.target.value)}>
              <option value="item_unavailable">Item unavailable</option>
              <option value="restaurant_too_busy">Too busy</option>
              <option value="closing_soon">Closing soon</option>
              <option value="cannot_fulfil_request">Cannot fulfil</option>
              <option value="incorrect_menu_information">Incorrect menu info</option>
              <option value="other">Other</option>
            </Select>
          </Field>
          <Field label="Explanation for customer (optional)" htmlFor="reject-exp">
            <Textarea id="reject-exp" value={rejectExplanation} onChange={(e) => setRejectExplanation(e.target.value)} />
          </Field>
          <Button variant="destructive" onClick={() => rejectMut.mutate()} loading={rejectMut.isPending}>Reject Order</Button>
        </div>
      </Modal>
    </AdminShell>
  );
}
