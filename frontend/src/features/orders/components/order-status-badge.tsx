import { Badge } from "@/components/ui/feedback";

const STATUS_MAP: Record<string, { label: string; tone: "success" | "warning" | "error" | "accent" | "info" | "neutral" }> = {
  pending_payment: { label: "Pending Payment", tone: "warning" },
  payment_failed: { label: "Payment Failed", tone: "error" },
  awaiting_restaurant: { label: "Awaiting Restaurant", tone: "warning" },
  accepted: { label: "Accepted", tone: "info" },
  preparing: { label: "Preparing", tone: "accent" },
  ready_for_pickup: { label: "Ready for Pickup", tone: "success" },
  completed_pickup: { label: "Completed", tone: "success" },
  rejected: { label: "Rejected", tone: "error" },
  cancelled: { label: "Cancelled", tone: "error" },
  expired: { label: "Expired", tone: "error" },
};

export function OrderStatusBadge({ status }: { status: string }) {
  const s = STATUS_MAP[status] ?? { label: status, tone: "neutral" as const };
  return <Badge tone={s.tone}>{s.label}</Badge>;
}
