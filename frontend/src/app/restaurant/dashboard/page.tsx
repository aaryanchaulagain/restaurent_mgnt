"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { Badge } from "@/components/ui/feedback";
import { Switch } from "@/components/ui/forms";
import { DataTable } from "@/components/ui/table";
import { menuItems, restaurantOrders } from "@/data/mock";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";
import { useState } from "react";

export default function RestaurantDashboardPage() {
  const [open, setOpen] = useState(true);
  const popular = menuItems.filter((i) => i.restaurantSlug === "himalayan-kitchen");

  return (
    <AdminShell
      brand="Himalayan Kitchen"
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Dashboard"
      subtitle="Today’s service overview"
      actions={<Switch label={open ? "Accepting orders" : "Paused"} checked={open} onChange={setOpen} />}
    >
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Today’s sales" value={formatCents(184500)} hint="12% commission applied later" />
        <StatCard label="Orders today" value="42" hint="6 pending acceptance" />
        <StatCard label="Average order" value={formatCents(4393)} />
        <StatCard label="Net earnings" value={formatCents(162360)} hint="After platform commission" />
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Popular items</h2>
          <ul className="mt-4 space-y-3">
            {popular.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-3 text-sm">
                <span>{item.name}</span>
                <span className="font-semibold">{formatCents(item.priceCents)}</span>
              </li>
            ))}
          </ul>
        </section>
        <section>
          <h2 className="mb-4 text-2xl">Recent orders</h2>
          <DataTable
            columns={[
              { key: "orderNumber", label: "Order" },
              { key: "customer", label: "Customer" },
              { key: "status", label: "Status" },
              { key: "total", label: "Total" },
            ]}
            rows={restaurantOrders.map((o) => ({
              orderNumber: o.orderNumber,
              customer: o.customerName,
              status: <Badge tone="accent">{o.status}</Badge>,
              total: formatCents(o.totalCents),
            }))}
          />
        </section>
      </div>
    </AdminShell>
  );
}
