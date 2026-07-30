import { cn } from "@/lib/utils";

export function DataTable({
  columns,
  rows,
  empty,
}: {
  columns: { key: string; label: string; className?: string }[];
  rows: Record<string, React.ReactNode>[];
  empty?: React.ReactNode;
}) {
  if (rows.length === 0) return <>{empty}</>;

  return (
    <div className="overflow-x-auto rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)]">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-[var(--surface-muted)] text-[var(--text-muted)]">
          <tr>
            {columns.map((col) => (
              <th
                key={col.key}
                className={cn("px-4 py-3 font-semibold whitespace-nowrap", col.className)}
              >
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr
              key={index}
              className="border-t border-[var(--border-subtle)] text-[var(--text-primary)]"
            >
              {columns.map((col) => (
                <td key={col.key} className={cn("px-4 py-3 align-middle", col.className)}>
                  {row[col.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
