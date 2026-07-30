import type { ApplicationStatus, RestaurantApplication } from "../types";
import { APPLICATION_STATUS_LABELS } from "../constants";

export function statusLabel(status: ApplicationStatus | string): string {
  return (
    APPLICATION_STATUS_LABELS[status as ApplicationStatus] ??
    status.replace(/_/g, " ")
  );
}

export type BadgeTone = "neutral" | "success" | "warning" | "error" | "info";

export function statusBadgeTone(status: ApplicationStatus | string): BadgeTone {
  switch (status) {
    case "approved":
      return "success";
    case "rejected":
    case "withdrawn":
      return "error";
    case "changes_requested":
      return "warning";
    case "submitted":
    case "resubmitted":
    case "under_review":
      return "info";
    default:
      return "neutral";
  }
}

const EDITABLE_STATUSES: ApplicationStatus[] = ["draft", "changes_requested"];

export function isEditableByApplicant(status: ApplicationStatus): boolean {
  return EDITABLE_STATUSES.includes(status);
}

export function canSubmitApplication(app: RestaurantApplication): boolean {
  return app.status === "draft";
}

export function canResubmitApplication(app: RestaurantApplication): boolean {
  return app.status === "changes_requested";
}

export function canWithdrawApplication(app: RestaurantApplication): boolean {
  return ["draft", "submitted", "under_review", "changes_requested", "resubmitted"].includes(
    app.status,
  );
}

export function displayApplicationName(app: RestaurantApplication): string {
  return app.trading_name || app.legal_business_name || "Untitled application";
}

export function primaryAddress(app: RestaurantApplication) {
  const addresses = app.addresses ?? [];
  return addresses.find((a) => a.is_primary) ?? addresses[0] ?? null;
}
