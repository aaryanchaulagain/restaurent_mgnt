import type { OrderTimeline } from "../api/order-api";

const LABELS: Record<string, string> = {
  awaiting_restaurant: "Order placed",
  accepted: "Accepted by restaurant",
  rejected: "Rejected",
  preparing: "Preparation started",
  ready_for_pickup: "Ready for pickup",
  completed_pickup: "Completed",
  cancelled: "Cancelled",
  expired: "Expired",
};

export function OrderStatusTimeline({ timeline }: { timeline: OrderTimeline[] }) {
  return (
    <ol className="space-y-3">
      {timeline.map((entry, i) => (
        <li key={i} className="flex items-start gap-3">
          <span className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[var(--color-burnt-orange)]" />
          <div>
            <p className="text-sm font-medium">{LABELS[entry.to] ?? entry.to}</p>
            {entry.at ? <p className="text-xs text-[var(--text-muted)]">{new Date(entry.at).toLocaleString()}</p> : null}
            {entry.reason ? <p className="text-xs text-[var(--text-secondary)]">{entry.reason}</p> : null}
          </div>
        </li>
      ))}
    </ol>
  );
}
