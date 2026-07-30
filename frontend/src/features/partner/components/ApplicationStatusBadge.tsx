"use client";

import { Badge } from "@/components/ui/feedback";
import { statusBadgeTone, statusLabel } from "../utils/status";
import type { ApplicationStatus } from "../types";

export function ApplicationStatusBadge({ status }: { status: ApplicationStatus | string }) {
  const tone = statusBadgeTone(status);
  return <Badge tone={tone}>{statusLabel(status)}</Badge>;
}
