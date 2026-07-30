"use client";

import { useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantOrderApi } from "@/features/orders/api/order-api";
import { restaurantPaymentAccountApi } from "@/features/payments/api/payment-api";
import { ownershipLabel, revenueOwnershipWording } from "@/features/payments/utils/ownership-label";

export default function RestaurantFinancePage() {
  const profile = useRestaurantProfile();

  const accountQuery = useQuery({
    queryKey: ["restaurant", "payment-account"],
    queryFn: async () => (await restaurantPaymentAccountApi.get()).data.payment_account,
  });

  const ordersQuery = useQuery({
    queryKey: ["restaurant", "finance-orders"],
    queryFn: async () => (await restaurantOrderApi.list()).data.orders,
  });

  const ownershipType = accountQuery.data?.ownership_type ?? "third_party";
  const isFirstParty = ownershipType === "first_party";

  const paidOrders = useMemo(
    () => (ordersQuery.data ?? []).filter((o) => o.payment_status === "paid"),
    [ordersQuery.data],
  );

  const grossCents = useMemo(
    () => paidOrders.reduce((sum, o) => sum + o.total_cents, 0),
    [paidOrders],
  );

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Finance"
      subtitle="Order payment summaries — not bank payouts"
    >
      <p className="mb-4 text-sm text-[var(--text-muted)]">
        {ownershipLabel(ownershipType)} · {revenueOwnershipWording(ownershipType)}
      </p>

      {ordersQuery.isLoading || accountQuery.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Paid orders" value={String(paidOrders.length)} />
            <StatCard label="Gross paid total" value={formatCents(grossCents)} hint="From restaurant orders API" />
            <StatCard
              label={isFirstParty ? "Platform-owned revenue" : "Estimated restaurant share"}
              value={isFirstParty ? formatCents(grossCents) : "Per order"}
              hint={
                isFirstParty
                  ? "First-party restaurant"
                  : "Open an order for payment-summary share — not a payout statement"
              }
            />
            <StatCard label="Online payments" value={accountQuery.data?.online_payments_enabled ? "On" : "Off"} />
          </div>

          {paidOrders.length === 0 ? (
            <div className="mt-6">
              <EmptyState title="No paid orders yet" description="Paid orders will appear in this summary." />
            </div>
          ) : (
            <div className="mt-6 overflow-x-auto rounded-lg border bg-white">
              <table className="min-w-full text-sm">
                <thead className="bg-[var(--surface-muted)] text-left">
                  <tr>
                    <th className="p-3">Order</th>
                    <th className="p-3">Payment</th>
                    <th className="p-3">Total</th>
                    <th className="p-3">Note</th>
                  </tr>
                </thead>
                <tbody>
                  {paidOrders.slice(0, 25).map((o) => (
                    <tr key={o.public_id} className="border-t">
                      <td className="p-3 font-medium">{o.order_number}</td>
                      <td className="p-3">
                        {o.payment_method.replace(/_/g, " ")} ·{" "}
                        <Badge tone="success">{o.payment_status}</Badge>
                      </td>
                      <td className="p-3">{formatCents(o.total_cents)}</td>
                      <td className="p-3 text-xs text-[var(--text-muted)]">{revenueOwnershipWording(ownershipType)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </AdminShell>
  );
}
