import { z } from "zod";
import { AUSTRALIAN_STATES } from "../constants";

const stateKeys = Object.keys(AUSTRALIAN_STATES) as [string, ...string[]];

export const contactStepSchema = z.object({
  primary_contact_name: z.string().min(1, "Contact name is required").max(255),
  primary_contact_email: z.string().email("Enter a valid email"),
  primary_contact_phone: z.string().min(1, "Phone is required").max(40),
  business_email: z.string().email("Enter a valid business email"),
  business_phone: z.string().min(1, "Business phone is required").max(40),
});

export const businessStepSchema = z.object({
  legal_business_name: z.string().min(1, "Legal business name is required").max(255),
  trading_name: z.string().min(1, "Trading name is required").max(255),
  business_type: z.enum(["sole_trader", "partnership", "company", "trust", "other"]),
  abn: z
    .string()
    .min(1, "ABN is required")
    .transform((v) => v.replace(/\D/g, ""))
    .refine((v) => v.length === 11, "ABN must be 11 digits"),
  business_registration_number: z.string().max(100).optional().or(z.literal("")),
  description: z.string().min(1, "Description is required").max(5000),
  cuisine_summary: z.string().min(1, "Cuisine summary is required").max(255),
  website_url: z
    .string()
    .url("Enter a valid URL")
    .optional()
    .or(z.literal("")),
});

export const addressStepSchema = z.object({
  address_line_1: z.string().min(1, "Street address is required").max(255),
  address_line_2: z.string().max(255).optional().or(z.literal("")),
  suburb: z.string().min(1, "Suburb is required").max(120),
  state: z.enum(stateKeys as [string, ...string[]]),
  postcode: z.string().regex(/^\d{4}$/, "Enter a 4-digit postcode"),
  country: z.string().length(2).default("AU"),
});

export const operationsStepSchema = z.object({
  service_type: z.enum([
    "delivery",
    "pickup",
    "dine_in",
    "delivery_and_pickup",
    "all",
  ]),
  expected_monthly_orders: z.string().max(40).optional().or(z.literal("")),
  current_delivery_method: z.string().max(255).optional().or(z.literal("")),
  location_count: z.coerce.number().int().min(1).max(100).optional(),
  referral_source: z.string().max(255).optional().or(z.literal("")),
});

export const submitApplicationSchema = z
  .object({
    terms: z.boolean(),
    confirm_accuracy: z.boolean(),
  })
  .refine((d) => d.terms === true, { message: "Accept the terms", path: ["terms"] })
  .refine((d) => d.confirm_accuracy === true, {
    message: "Confirm your information is accurate",
    path: ["confirm_accuracy"],
  });

export const rejectApplicationSchema = z.object({
  category: z.string().min(1, "Select a category"),
  reason: z.string().min(1, "Reason is required").max(5000),
  internal_note: z.string().max(5000).optional().or(z.literal("")),
  password: z.string().min(1, "Password is required"),
});

export const approveApplicationSchema = z.object({
  password: z.string().min(1, "Password is required"),
});

export function zodFieldErrors(error: z.ZodError): Record<string, string> {
  const next: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = String(issue.path[0] ?? "form");
    if (!next[key]) next[key] = issue.message;
  }
  return next;
}
