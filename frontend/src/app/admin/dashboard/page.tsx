import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { Badge } from "@/components/ui/feedback";
import { DataTable } from "@/components/ui/table";
import { auditLogs, platformOrders, restaurants } from "@/data/mock";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

export default function AdminDashboardPage() {
  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Platform dashboard"
      subtitle="Marketplace health across all restaurants"
    >
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Restaurants" value={String(restaurants.length)} hint="1 pending review" />
        <StatCard label="Orders today" value="186" />
        <StatCard label="Gross GMV" value={formatCents(8421500)} />
        <StatCard label="Commission revenue" value={formatCents(1010580)} />
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <section>
          <h2 className="mb-4 text-2xl">Top restaurants</h2>
          <DataTable
            columns={[
              { key: "name", label: "Restaurant" },
              { key: "commission", label: "Commission" },
              { key: "status", label: "Status" },
            ]}
            rows={restaurants.slice(0, 5).map((r) => ({
              name: r.name,
              commission: `${r.commissionRate}%`,
              status: (
                <Badge tone={r.isOpen ? "success" : "warning"}>
                  {r.isOpen ? "Active" : "Closed"}
                </Badge>
              ),
            }))}
          />
        </section>
        <section>
          <h2 className="mb-4 text-2xl">Recent platform orders</h2>
          <DataTable
            columns={[
              { key: "order", label: "Order" },
              { key: "restaurant", label: "Restaurant" },
              { key: "total", label: "Total" },
            ]}
            rows={platformOrders.slice(0, 5).map((o) => ({
              order: o.orderNumber,
              restaurant: o.restaurantName,
              total: formatCents(o.totalCents),
            }))}
          />
          <h2 className="mt-8 mb-4 text-2xl">Security & audit</h2>
          <ul className="space-y-3 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4 text-sm">
            {auditLogs.map((log) => (
              <li key={log.id}>
                <p className="font-medium">{log.action}</p>
                <p className="text-[var(--text-muted)]">
                  {log.actor} · {log.subject} · {log.at}
                </p>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </AdminShell>
  );
}
