"use client";

import { Field, Input, Select, Textarea } from "@/components/ui/forms";
import { BUSINESS_TYPES } from "../constants";

export type BusinessDetailsValues = {
  legal_business_name: string;
  trading_name: string;
  business_type: string;
  abn: string;
  business_registration_number: string;
  description: string;
  cuisine_summary: string;
  website_url: string;
};

type Props = {
  values: BusinessDetailsValues;
  errors?: Record<string, string>;
  onChange: (patch: Partial<BusinessDetailsValues>) => void;
  disabled?: boolean;
};

export function BusinessDetailsForm({ values, errors, onChange, disabled }: Props) {
  return (
    <div className="space-y-4">
      <Field label="Legal business name" htmlFor="legal_business_name" error={errors?.legal_business_name}>
        <Input
          id="legal_business_name"
          value={values.legal_business_name}
          disabled={disabled}
          onChange={(e) => onChange({ legal_business_name: e.target.value })}
        />
      </Field>
      <Field label="Trading name" htmlFor="trading_name" error={errors?.trading_name}>
        <Input
          id="trading_name"
          value={values.trading_name}
          disabled={disabled}
          onChange={(e) => onChange({ trading_name: e.target.value })}
        />
      </Field>
      <Field label="Business type" htmlFor="business_type" error={errors?.business_type}>
        <Select
          id="business_type"
          value={values.business_type}
          disabled={disabled}
          onChange={(e) => onChange({ business_type: e.target.value })}
        >
          <option value="">Select type</option>
          {BUSINESS_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="ABN" htmlFor="abn" error={errors?.abn} hint="11-digit Australian Business Number">
        <Input
          id="abn"
          value={values.abn}
          disabled={disabled}
          placeholder="12 345 678 901"
          onChange={(e) => onChange({ abn: e.target.value })}
        />
      </Field>
      <Field
        label="Business registration number"
        htmlFor="business_registration_number"
        error={errors?.business_registration_number}
      >
        <Input
          id="business_registration_number"
          value={values.business_registration_number}
          disabled={disabled}
          onChange={(e) => onChange({ business_registration_number: e.target.value })}
        />
      </Field>
      <Field label="Cuisine summary" htmlFor="cuisine_summary" error={errors?.cuisine_summary}>
        <Input
          id="cuisine_summary"
          placeholder="Nepali, Indian, vegetarian…"
          value={values.cuisine_summary}
          disabled={disabled}
          onChange={(e) => onChange({ cuisine_summary: e.target.value })}
        />
      </Field>
      <Field label="Business description" htmlFor="description" error={errors?.description}>
        <Textarea
          id="description"
          placeholder="Tell guests what makes your kitchen special"
          value={values.description}
          disabled={disabled}
          onChange={(e) => onChange({ description: e.target.value })}
        />
      </Field>
      <Field label="Website (optional)" htmlFor="website_url" error={errors?.website_url}>
        <Input
          id="website_url"
          type="url"
          placeholder="https://"
          value={values.website_url}
          disabled={disabled}
          onChange={(e) => onChange({ website_url: e.target.value })}
        />
      </Field>
    </div>
  );
}
