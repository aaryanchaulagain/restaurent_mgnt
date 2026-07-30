import type {
  ApplicationStatus,
  BusinessType,
  DocumentType,
  ServiceType,
} from "../types";

export const DEFAULT_COUNTRY = "AU";
export const PARTNER_TERMS_VERSION =
  process.env.NEXT_PUBLIC_PARTNER_TERMS_VERSION ?? "2026-07-01";

export const AUSTRALIAN_STATES: Record<string, string> = {
  NSW: "New South Wales",
  VIC: "Victoria",
  QLD: "Queensland",
  SA: "South Australia",
  WA: "Western Australia",
  TAS: "Tasmania",
  ACT: "Australian Capital Territory",
  NT: "Northern Territory",
};

export const BUSINESS_TYPES: { value: BusinessType; label: string }[] = [
  { value: "sole_trader", label: "Sole trader" },
  { value: "partnership", label: "Partnership" },
  { value: "company", label: "Company" },
  { value: "trust", label: "Trust" },
  { value: "other", label: "Other" },
];

export const SERVICE_TYPES: { value: ServiceType; label: string }[] = [
  { value: "delivery", label: "Delivery only" },
  { value: "pickup", label: "Pickup only" },
  { value: "dine_in", label: "Dine in" },
  { value: "delivery_and_pickup", label: "Delivery & pickup" },
  { value: "all", label: "Delivery, pickup & dine in" },
];

export const DOCUMENT_TYPES: { value: DocumentType; label: string; required?: boolean }[] = [
  { value: "business_registration", label: "Business registration", required: true },
  { value: "food_business_licence", label: "Food business licence", required: true },
  { value: "owner_identification", label: "Owner identification", required: true },
  { value: "abn_document", label: "ABN document" },
  { value: "public_liability_insurance", label: "Public liability insurance" },
  { value: "bank_account_evidence", label: "Bank account evidence" },
  { value: "other", label: "Other supporting document" },
];

export const REQUIRED_DOCUMENT_TYPES = DOCUMENT_TYPES.filter((d) => d.required).map(
  (d) => d.value,
);

export const REJECTION_CATEGORIES: { value: string; label: string }[] = [
  { value: "incomplete_information", label: "Incomplete information" },
  { value: "invalid_documents", label: "Invalid documents" },
  { value: "unsupported_location", label: "Unsupported location" },
  { value: "compliance_issue", label: "Compliance issue" },
  { value: "duplicate_business", label: "Duplicate business" },
  { value: "commercial_decision", label: "Commercial decision" },
  { value: "other", label: "Other" },
];

export const COMMISSION_TYPES = [
  { value: "percentage", label: "Percentage" },
  { value: "fixed", label: "Fixed fee" },
  { value: "percentage_plus_fixed", label: "Percentage + fixed" },
  { value: "custom", label: "Custom" },
];

export const SETTLEMENT_FREQUENCIES = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "fortnightly", label: "Fortnightly" },
  { value: "monthly", label: "Monthly" },
];

export const APPLICATION_STATUS_LABELS: Record<ApplicationStatus, string> = {
  draft: "Draft",
  submitted: "Submitted",
  under_review: "Under review",
  changes_requested: "Changes requested",
  resubmitted: "Resubmitted",
  approved: "Approved",
  rejected: "Rejected",
  withdrawn: "Withdrawn",
  expired: "Expired",
};

export const WIZARD_STEPS = [
  { id: "contact" as const, label: "Contact" },
  { id: "business" as const, label: "Business" },
  { id: "address" as const, label: "Address" },
  { id: "operations" as const, label: "Operations" },
  { id: "documents" as const, label: "Documents" },
  { id: "review" as const, label: "Review" },
];

export const ADMIN_SORT_OPTIONS = [
  { value: "newest", label: "Newest first" },
  { value: "oldest", label: "Oldest first" },
  { value: "updated", label: "Recently updated" },
  { value: "incomplete_documents", label: "Incomplete documents" },
  { value: "awaiting_decision", label: "Awaiting decision" },
];

export const ADMIN_STATUS_FILTERS: { value: string; label: string }[] = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "submitted", label: "Submitted" },
  { value: "under_review", label: "Under review" },
  { value: "changes_requested", label: "Changes requested" },
  { value: "resubmitted", label: "Resubmitted" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
  { value: "withdrawn", label: "Withdrawn" },
];
