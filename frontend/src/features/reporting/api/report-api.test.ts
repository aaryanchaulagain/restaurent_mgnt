import { describe, expect, it } from "vitest";
import {
  REPORT_ERROR_MESSAGES,
  reportErrorMessage,
} from "@/features/reporting/api/report-api";

describe("report error mapping", () => {
  it("maps structured report codes", () => {
    expect(reportErrorMessage({ code: "REPORT_DATE_RANGE_TOO_LARGE", message: "x" })).toBe(
      REPORT_ERROR_MESSAGES.REPORT_DATE_RANGE_TOO_LARGE,
    );
    expect(reportErrorMessage({ code: "REPORT_FINANCE_PERMISSION_DENIED", message: "x" })).toBe(
      REPORT_ERROR_MESSAGES.REPORT_FINANCE_PERMISSION_DENIED,
    );
  });

  it("falls back to message for unknown codes", () => {
    expect(reportErrorMessage({ code: "OTHER", message: "Raw" })).toBe("Raw");
  });
});
