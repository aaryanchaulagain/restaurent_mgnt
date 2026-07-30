import Link from "next/link";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/table";
import { restaurants } from "@/data/mock";
import { adminNav } from "@/lib/admin-nav";

export default function AdminCommissionsPage() {
  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Commissions"
      subtitle="Per-restaurant commission agreements"
    >
      <DataTable
        columns={[
          { key: "name", label: "Restaurant" },
          { key: "rate", label: "Rate" },
          { key: "actions", label: "Actions" },
        ]}
        rows={restaurants.map((r) => ({
          name: r.name,
          rate: `${r.commissionRate}%`,
          actions: (
            <Link href={`/admin/restaurants/${r.id}`}>
              <Button size="sm" variant="outline">
                Adjust
              </Button>
            </Link>
          ),
        }))}
      />
    </AdminShell>
  );
}
