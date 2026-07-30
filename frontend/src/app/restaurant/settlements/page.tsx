import { AdminShell } from "@/components/layout/admin-shell";
import { StatCard } from "@/components/marketplace/cards";
import { Badge } from "@/components/ui/feedback";
import { DataTable } from "@/components/ui/table";
import { settlements } from "@/data/mock";
import { restaurantNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

export default function RestaurantSettlementsPage() {
  const rows = settlements.filter((s) => s.restaurantName === "Himalayan Kitchen");

  return (
    <AdminShell
      brand="Himalayan Kitchen"
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Settlements"
      subtitle="Weekly settlement statements and payout status"
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatCard label="Pending payout" value={formatCents(rows[0]?.netCents ?? 0)} />
        <StatCard label="Commission held" value={formatCents(rows[0]?.commissionCents ?? 0)} />
        <StatCard label="Statements" value={String(rows.length)} />
      </div>
      <DataTable
        columns={[
          { key: "period", label: "Period" },
          { key: "gross", label: "Gross" },
          { key: "commission", label: "Commission" },
          { key: "net", label: "Net" },
          { key: "status", label: "Status" },
        ]}
        rows={rows.map((s) => ({
          period: s.period,
          gross: formatCents(s.grossCents),
          commission: formatCents(s.commissionCents),
          net: formatCents(s.netCents),
          status: (
            <Badge tone={s.status === "Paid" ? "success" : "warning"}>{s.status}</Badge>
          ),
        }))}
      />
    </AdminShell>
  );
}
