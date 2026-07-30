"use client";

import type { StatusHistoryEntry } from "../types";
import { statusLabel } from "../utils/status";

export function ApplicationTimeline({ history }: { history: StatusHistoryEntry[] }) {
  if (!history.length) {
    return (
      <p className="text-sm text-[var(--text-secondary)]">No status updates yet.</p>
    );
  }

  const sorted = [...history].sort(
    (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
  );

  return (
    <ol className="space-y-4 border-l border-[var(--border-subtle)] pl-4">
      {sorted.map((entry, index) => (
        <li key={`${entry.created_at}-${index}`} className="relative">
          <span className="absolute -left-[1.35rem] top-1.5 h-2.5 w-2.5 rounded-full bg-[var(--color-burnt-orange)]" />
          <p className="text-sm font-medium">{statusLabel(entry.new_status)}</p>
          {entry.reason ? (
            <p className="mt-1 text-sm text-[var(--text-secondary)]">{entry.reason}</p>
          ) : null}
          <time className="mt-1 block text-xs text-[var(--text-muted)]">
            {new Date(entry.created_at).toLocaleString()}
          </time>
        </li>
      ))}
    </ol>
  );
}
