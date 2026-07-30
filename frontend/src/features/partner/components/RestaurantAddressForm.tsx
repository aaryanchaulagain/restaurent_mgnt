"use client";

import { Field, Input, Select } from "@/components/ui/forms";
import { AUSTRALIAN_STATES, DEFAULT_COUNTRY } from "../constants";

export type AddressFormValues = {
  address_line_1: string;
  address_line_2: string;
  suburb: string;
  state: string;
  postcode: string;
  country: string;
};

type Props = {
  values: AddressFormValues;
  errors?: Record<string, string>;
  onChange: (patch: Partial<AddressFormValues>) => void;
  disabled?: boolean;
};

export function RestaurantAddressForm({ values, errors, onChange, disabled }: Props) {
  return (
    <div className="space-y-4">
      <Field label="Street address" htmlFor="address_line_1" error={errors?.address_line_1}>
        <Input
          id="address_line_1"
          value={values.address_line_1}
          disabled={disabled}
          onChange={(e) => onChange({ address_line_1: e.target.value })}
        />
      </Field>
      <Field label="Address line 2 (optional)" htmlFor="address_line_2" error={errors?.address_line_2}>
        <Input
          id="address_line_2"
          value={values.address_line_2}
          disabled={disabled}
          onChange={(e) => onChange({ address_line_2: e.target.value })}
        />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Suburb" htmlFor="suburb" error={errors?.suburb}>
          <Input
            id="suburb"
            value={values.suburb}
            disabled={disabled}
            onChange={(e) => onChange({ suburb: e.target.value })}
          />
        </Field>
        <Field label="State" htmlFor="state" error={errors?.state}>
          <Select
            id="state"
            value={values.state}
            disabled={disabled}
            onChange={(e) => onChange({ state: e.target.value })}
          >
            <option value="">Select state</option>
            {Object.entries(AUSTRALIAN_STATES).map(([code, name]) => (
              <option key={code} value={code}>
                {name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Postcode" htmlFor="postcode" error={errors?.postcode}>
          <Input
            id="postcode"
            inputMode="numeric"
            maxLength={4}
            value={values.postcode}
            disabled={disabled}
            onChange={(e) => onChange({ postcode: e.target.value })}
          />
        </Field>
        <Field label="Country" htmlFor="country" error={errors?.country}>
          <Input
            id="country"
            value={values.country || DEFAULT_COUNTRY}
            disabled
            readOnly
          />
        </Field>
      </div>
    </div>
  );
}

export function emptyAddressValues(): AddressFormValues {
  return {
    address_line_1: "",
    address_line_2: "",
    suburb: "",
    state: "",
    postcode: "",
    country: DEFAULT_COUNTRY,
  };
}
