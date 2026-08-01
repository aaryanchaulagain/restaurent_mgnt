import { ApiError } from "@/lib/api/client";

export type FieldErrors = Record<string, string[]>;

/**
 * Laravel returns per-field messages in `errors` but a generic
 * "Validation failed." message. Pull the field messages out so forms can
 * show what actually went wrong.
 */
export function fieldErrorsOf(error: unknown): FieldErrors {
  if (error instanceof ApiError && error.errors) return error.errors;
  return {};
}

export function firstFieldError(errors: FieldErrors, key: string): string | undefined {
  return errors[key]?.[0];
}

/** Flat, readable summary for a toast description. */
export function summarizeErrors(error: unknown, fallback = "Request failed"): string {
  const errors = fieldErrorsOf(error);
  const messages = Object.values(errors).flat();

  if (messages.length > 0) {
    return messages.slice(0, 3).join(" ");
  }
  if (error instanceof ApiError) return error.message;
  return fallback;
}
