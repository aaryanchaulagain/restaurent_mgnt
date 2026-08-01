import { AdminShell } from "@/components/layout/admin-shell";
import { Badge } from "@/components/ui/feedback";
import { DataTable } from "@/components/ui/table";
import { settlements } from "@/data/mock";
import { adminNav } from "@/lib/admin-nav";
import { formatCents } from "@/lib/utils";

export default function AdminSettlementsPage() {
  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Settlements"
      subtitle="Restaurant payout cycles and commission deductions"
    >
      <DataTable
        columns={[
          { key: "restaurant", label: "Restaurant" },
          { key: "period", label: "Period" },
          { key: "gross", label: "Gross" },
          { key: "commission", label: "Commission" },
          { key: "net", label: "Net payout" },
          { key: "status", label: "Status" },
        ]}
        rows={settlements.map((s) => ({
          restaurant: s.restaurantName,
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
