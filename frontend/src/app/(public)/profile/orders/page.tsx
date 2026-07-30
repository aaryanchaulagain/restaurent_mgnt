"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Breadcrumbs } from "@/components/ui/navigation";
import { CustomerGuard } from "@/features/auth/guards/route-guard";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { OrderStatusBadge } from "@/features/orders/components/order-status-badge";
import { formatCents } from "@/lib/utils";

export default function ProfileOrdersPage() {
  const orders = useQuery({
    queryKey: ["customer-orders"],
    queryFn: async () => (await customerOrderApi.list()).data.orders,
  });

  return (
    <CustomerGuard>
      <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Profile", href: "/profile" }, { label: "Orders" }]} />
        <h1 className="mt-4 text-3xl font-bold">Order history</h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">Track recent orders and view past purchases.</p>

        {orders.isLoading ? <Skeleton className="mt-8 h-64 w-full" /> : (orders.data ?? []).length === 0 ? (
          <div className="mt-8">
            <EmptyState title="No orders yet" description="When you place an order, it will appear here." action={<Link href="/restaurants"><Button>Browse restaurants</Button></Link>} />
          </div>
        ) : (
          <ul className="mt-8 space-y-3">
            {(orders.data ?? []).map((order) => (
              <li key={order.public_id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-white p-4 shadow-sm">
                <div>
                  <p className="font-semibold">{order.order_number}</p>
                  <p className="text-sm text-[var(--text-secondary)]">
                    {formatCents(order.total_cents)} · {order.fulfilment_type} · {order.placed_at ? new Date(order.placed_at).toLocaleDateString() : ""}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <OrderStatusBadge status={order.status} />
                  <Link href={`/orders/${order.order_number}`}>
                    <Button size="sm" variant="outline">View</Button>
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        )}
      </main>
    </CustomerGuard>
  );
}
