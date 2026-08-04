import { apiGet } from "@/lib/api/client";

export type ReportRange =
  | "today"
  | "last_7_days"
  | "last_30_days"
  | "this_month"
  | "previous_month"
  | "custom";

export type ReportMeta = {
  range: string;
  start_at: string;
  end_at: string;
  timezone: string;
  generated_at: string;
};

export type ReportSummary = {
  total_orders: number;
  completed_orders: number;
  cancelled_orders: number;
  rejected_orders: number;
  expired_orders: number;
  active_orders: number;
  gross_order_value_cents: number;
  paid_amount_cents: number | null;
  refunded_amount_cents: number | null;
  average_order_value_cents: number;
  low_stock_count: number;
  out_of_stock_count: number;
};

export type BranchReportRow = {
  public_id: string;
  name: string;
  status: string;
  accepting_orders: boolean;
  total_orders: number;
  completed_orders: number;
  cancelled_orders: number;
  rejected_orders: number;
  expired_orders: number;
  active_orders: number;
  gross_order_value_cents: number;
  average_order_value_cents: number;
  paid_amount_cents: number | null;
  refunded_amount_cents: number | null;
  low_stock_count: number;
  out_of_stock_count: number;
  tracked_inventory_count: number;
  average_acceptance_seconds: number | null;
  average_preparation_seconds: number | null;
};

export type BusinessReportPayload = {
  meta: ReportMeta;
  business: {
    public_id: string;
    name: string;
    business_type: string;
  };
  branch_counts?: {
    total: number;
    active: number;
    temporarily_closed: number;
  };
  summary: ReportSummary;
  branches: BranchReportRow[];
  order_status_breakdown: Array<{ status: string; count: number }>;
  fulfilment_breakdown: Array<{ fulfilment_type: string; count: number }>;
  payment_breakdown: {
    by_status: Array<{ status: string; count: number }>;
    paid_amount_cents: number | null;
    refunded_amount_cents: number | null;
  } | null;
  viewer?: string;
};

export type BranchReportPayload = {
  meta: ReportMeta;
  business: {
    public_id: string;
    name: string;
    business_type: string;
  };
  branch: {
    public_id: string;
    name: string;
    status: string;
    accepting_orders: boolean;
    restaurant_slug: string | null;
  };
  summary: ReportSummary;
  metrics: BranchReportRow | null;
  order_status_breakdown: Array<{ status: string; count: number }>;
  fulfilment_breakdown: Array<{ fulfilment_type: string; count: number }>;
  payment_breakdown: BusinessReportPayload["payment_breakdown"];
  inventory?: {
    tracked_inventory_count: number;
    low_stock_count: number;
    out_of_stock_count: number;
    availability_only_count: number;
  };
  configuration?: {
    has_coordinates: boolean;
    timezone: string | null;
    linked_restaurant_slug: string | null;
    delivery_enabled: boolean;
    pickup_enabled: boolean;
  };
  viewer?: string;
};

export const REPORT_ERROR_MESSAGES: Record<string, string> = {
  REPORT_ACCESS_DENIED: "You do not have permission to view this report.",
  REPORT_BUSINESS_NOT_FOUND: "Business not found.",
  REPORT_BRANCH_NOT_FOUND: "Branch not found.",
  REPORT_BRANCH_BUSINESS_MISMATCH: "Branch does not belong to this business.",
  REPORT_DATE_RANGE_INVALID: "That date range is invalid.",
  REPORT_DATE_RANGE_TOO_LARGE: "Date range cannot exceed one year.",
  REPORT_TIMEZONE_INVALID: "Reporting timezone is invalid.",
  REPORT_FINANCE_PERMISSION_DENIED: "You do not have permission to view finance reports.",
  REPORT_DATA_UNAVAILABLE: "Report data is temporarily unavailable.",
};

export function reportErrorMessage(err: { code?: string | null; message: string }): string {
  if (err.code && REPORT_ERROR_MESSAGES[err.code]) {
    return REPORT_ERROR_MESSAGES[err.code];
  }
  return err.message;
}

function qs(params: Record<string, string | undefined>): string {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v) search.set(k, v);
  });
  const s = search.toString();
  return s ? `?${s}` : "";
}

export const partnerReportApi = {
  businessSummary(
    businessPublicId: string,
    params: { range: ReportRange; start?: string; end?: string },
  ) {
    return apiGet<BusinessReportPayload>(
      `/api/v1/businesses/${businessPublicId}/reports/summary${qs(params)}`,
    );
  },

  branchSummary(
    businessPublicId: string,
    branchPublicId: string,
    params: { range: ReportRange; start?: string; end?: string },
  ) {
    return apiGet<BranchReportPayload>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/reports/summary${qs(params)}`,
    );
  },
};

export const adminReportApi = {
  businessSummary(
    businessPublicId: string,
    params: { range: ReportRange; start?: string; end?: string },
  ) {
    return apiGet<BusinessReportPayload>(
      `/api/v1/admin/businesses/${businessPublicId}/reports/summary${qs(params)}`,
    );
  },

  branchSummary(
    businessPublicId: string,
    branchPublicId: string,
    params: { range: ReportRange; start?: string; end?: string },
  ) {
    return apiGet<BranchReportPayload>(
      `/api/v1/admin/businesses/${businessPublicId}/branches/${branchPublicId}/reports/summary${qs(params)}`,
    );
  },
};
