"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { DataTable } from "@/components/ui/table";
import { restaurantOrderApi, type OrderDetail } from "@/features/orders/api/order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { readBranchDashboardContext } from "@/features/business/lib/branch-context";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

/** Orders in these states never produced revenue. */
const NON_REVENUE_STATUSES = new Set(["rejected", "cancelled"]);

/**
 * Identifies the tenant the dashboard is currently scoped to, so React Query
 * cannot serve one business's figures to another after a branch or user switch.
 */
function useTenantKey(): string {
  const [key, setKey] = useState("ssr");

  useEffect(() => {
    const read = () => {
      const ctx = readBranchDashboardContext();
      setKey(
        ctx.aggregate
          ? "aggregate"
          : (ctx.branchPublicId ?? ctx.restaurantPublicId ?? "membership"),
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

function isToday(iso: string | null | undefined): boolean {
  if (!iso) return false;
  const placed = new Date(iso);
  if (Number.isNaN(placed.getTime())) return false;
  const now = new Date();
  return (
    placed.getFullYear() === now.getFullYear() &&
    placed.getMonth() === now.getMonth() &&
    placed.getDate() === now.getDate()
  );
}

function orderTimestamp(order: OrderDetail): number {
  const value = order.placed_at ?? order.completed_at ?? order.accepted_at;
  const parsed = value ? new Date(value).getTime() : Number.NaN;
  return Number.isNaN(parsed) ? 0 : parsed;
}

export default function RestaurantDashboardPage() {
  const { user } = useAuth();
  const tenantKey = useTenantKey();
  const profile = useRestaurantProfile();

  const ordersQuery = useQuery({
    queryKey: ["restaurant", user?.id ?? "anon", tenantKey, "dashboard-orders"],
    queryFn: async () => (await restaurantOrderApi.list()).data.orders,
    enabled: tenantKey !== "ssr",
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  });

  const orders = useMemo(() => ordersQuery.data ?? [], [ordersQuery.data]);

  const todaysOrders = useMemo(
    () => orders.filter((order) => isToday(order.placed_at)),
    [orders],
  );

  const revenueOrders = useMemo(
    () => todaysOrders.filter((order) => !NON_REVENUE_STATUSES.has(order.status)),
    [todaysOrders],
  );

  const salesCents = useMemo(
    () => revenueOrders.reduce((sum, order) => sum + order.total_cents, 0),
    [revenueOrders],
  );

  const paidCents = useMemo(
    () =>
      todaysOrders
        .filter((order) => order.payment_status === "paid")
        .reduce((sum, order) => sum + order.total_cents, 0),
    [todaysOrders],
  );

  const averageCents = revenueOrders.length
    ? Math.round(salesCents / revenueOrders.length)
    : 0;

  const pendingCount = useMemo(
    () => orders.filter((order) => order.status === "awaiting_restaurant").length,
    [orders],
  );

  const popular = useMemo(() => {
    const totals = new Map<string, { name: string; quantity: number; revenueCents: number }>();
    for (const order of orders) {
      if (NON_REVENUE_STATUSES.has(order.status)) continue;
      for (const item of order.items ?? []) {
        const existing = totals.get(item.name) ?? {
          name: item.name,
          quantity: 0,
          revenueCents: 0,
        };
        existing.quantity += item.quantity;
        existing.revenueCents += item.line_total_cents;
        totals.set(item.name, existing);
      }
    }
    return [...totals.values()].sort((a, b) => b.quantity - a.quantity).slice(0, 5);
  }, [orders]);

  const recent = useMemo(
    () => [...orders].sort((a, b) => orderTimestamp(b) - orderTimestamp(a)).slice(0, 8),
    [orders],
  );

  const loading = ordersQuery.isLoading || tenantKey === "ssr";

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Dashboard"
      subtitle="Today’s service overview"
    >
      {ordersQuery.isError ? (
        <div className="mb-6 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4 text-sm text-[var(--text-secondary)]">
          Could not load orders for this branch. Select a branch above and try again.
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {loading ? (
          <>
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
          </>
        ) : (
          <>
            <StatCard label="Today’s sales" value={formatCents(salesCents)} />
            <StatCard
              label="Orders today"
              value={String(revenueOrders.length)}
              hint={pendingCount > 0 ? `${pendingCount} pending acceptance` : undefined}
            />
            <StatCard label="Average order" value={formatCents(averageCents)} />
            <StatCard
              label="Paid today"
              value={formatCents(paidCents)}
              hint="Commission settled separately"
            />
          </>
        )}
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Popular items</h2>
          {loading ? (
            <Skeleton className="mt-4 h-32" />
          ) : popular.length === 0 ? (
            <p className="mt-4 text-sm text-[var(--text-secondary)]">No sales data yet</p>
          ) : (
            <ul className="mt-4 space-y-3">
              {popular.map((item) => (
                <li
                  key={item.name}
                  className="flex items-center justify-between gap-3 text-sm"
                >
                  <span>
                    {item.name}
                    <span className="ml-2 text-[var(--text-muted)]">×{item.quantity}</span>
                  </span>
                  <span className="font-semibold">{formatCents(item.revenueCents)}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section>
          <h2 className="mb-4 text-2xl">Recent orders</h2>
          {loading ? (
            <Skeleton className="h-48" />
          ) : recent.length === 0 ? (
            <EmptyState
              title="No recent orders"
              description="Orders placed at this branch will appear here."
            />
          ) : (
            <DataTable
              columns={[
                { key: "orderNumber", label: "Order" },
                { key: "customer", label: "Customer" },
                { key: "status", label: "Status" },
                { key: "total", label: "Total" },
              ]}
              rows={recent.map((order) => ({
                orderNumber: order.order_number,
                customer: order.customer_name ?? "Guest",
                status: <OrderStatusBadge status={order.status} />,
                total: formatCents(order.total_cents),
              }))}
            />
          )}
        </section>
      </div>
    </AdminShell>
  );
}
