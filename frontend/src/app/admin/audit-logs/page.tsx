import { AdminShell } from "@/components/layout/admin-shell";
import { DataTable } from "@/components/ui/table";
import { auditLogs } from "@/data/mock";
import { adminNav } from "@/lib/admin-nav";

export default function AdminAuditLogsPage() {
  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Audit logs"
      subtitle="Sensitive actions with actor, subject and timestamp"
    >
      <DataTable
        columns={[
          { key: "at", label: "When" },
          { key: "actor", label: "Actor" },
          { key: "action", label: "Action" },
          { key: "subject", label: "Subject" },
        ]}
        rows={auditLogs.map((log) => ({
          at: log.at,
          actor: log.actor,
          action: log.action,
          subject: log.subject,
        }))}
      />
    </AdminShell>
  );
}
