import { AdminShell } from "@/components/layout/admin-shell";
import { Badge } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/table";
import { supportTickets } from "@/data/mock";
import { adminNav } from "@/lib/admin-nav";

export default function AdminSupportPage() {
  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Support"
      subtitle="Customer and restaurant support queue"
    >
      <DataTable
        columns={[
          { key: "subject", label: "Subject" },
          { key: "requester", label: "Requester" },
          { key: "priority", label: "Priority" },
          { key: "status", label: "Status" },
          { key: "actions", label: "Actions" },
        ]}
        rows={supportTickets.map((t) => ({
          subject: t.subject,
          requester: t.requester,
          priority: (
            <Badge
              tone={
                t.priority === "High" ? "error" : t.priority === "Medium" ? "warning" : "neutral"
              }
            >
              {t.priority}
            </Badge>
          ),
          status: <Badge tone="info">{t.status}</Badge>,
          actions: (
            <Button size="sm" variant="ghost">
              Open
            </Button>
          ),
        }))}
      />
    </AdminShell>
  );
}
