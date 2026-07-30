"use client";

import { Field, Input, Select } from "@/components/ui/forms";
import { SERVICE_TYPES } from "../constants";

export type OperationsFormValues = {
  service_type: string;
  expected_monthly_orders: string;
  current_delivery_method: string;
  location_count: string;
  referral_source: string;
};

type Props = {
  values: OperationsFormValues;
  errors?: Record<string, string>;
  onChange: (patch: Partial<OperationsFormValues>) => void;
  disabled?: boolean;
};

export function OperationsForm({ values, errors, onChange, disabled }: Props) {
  return (
    <div className="space-y-4">
      <Field label="Service type" htmlFor="service_type" error={errors?.service_type}>
        <Select
          id="service_type"
          value={values.service_type}
          disabled={disabled}
          onChange={(e) => onChange({ service_type: e.target.value })}
        >
          <option value="">Select service type</option>
          {SERVICE_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
      </Field>
      <Field
        label="Expected monthly orders"
        htmlFor="expected_monthly_orders"
        error={errors?.expected_monthly_orders}
      >
        <Input
          id="expected_monthly_orders"
          placeholder="e.g. 100–200"
          value={values.expected_monthly_orders}
          disabled={disabled}
          onChange={(e) => onChange({ expected_monthly_orders: e.target.value })}
        />
      </Field>
      <Field
        label="Current delivery method"
        htmlFor="current_delivery_method"
        error={errors?.current_delivery_method}
      >
        <Input
          id="current_delivery_method"
          placeholder="In-house, third-party apps…"
          value={values.current_delivery_method}
          disabled={disabled}
          onChange={(e) => onChange({ current_delivery_method: e.target.value })}
        />
      </Field>
      <Field label="Number of locations" htmlFor="location_count" error={errors?.location_count}>
        <Input
          id="location_count"
          type="number"
          min={1}
          value={values.location_count}
          disabled={disabled}
          onChange={(e) => onChange({ location_count: e.target.value })}
        />
      </Field>
      <Field label="How did you hear about us?" htmlFor="referral_source" error={errors?.referral_source}>
        <Input
          id="referral_source"
          value={values.referral_source}
          disabled={disabled}
          onChange={(e) => onChange({ referral_source: e.target.value })}
        />
      </Field>
    </div>
  );
}
