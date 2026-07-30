"use client";

import { Checkbox } from "@/components/ui/forms";
import { PARTNER_TERMS_VERSION } from "../constants";

type Props = {
  terms: boolean;
  confirmAccuracy: boolean;
  onTermsChange: (value: boolean) => void;
  onConfirmChange: (value: boolean) => void;
  errors?: Record<string, string>;
  disabled?: boolean;
};

export function TermsAcceptance({
  terms,
  confirmAccuracy,
  onTermsChange,
  onConfirmChange,
  errors,
  disabled,
}: Props) {
  return (
    <div className="space-y-3 rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-4">
      <Checkbox
        label={`I accept the partner terms (version ${PARTNER_TERMS_VERSION})`}
        checked={terms}
        disabled={disabled}
        onChange={(e) => onTermsChange(e.target.checked)}
      />
      {errors?.terms ? (
        <p className="text-xs text-[var(--color-error)]">{errors.terms}</p>
      ) : null}
      <Checkbox
        label="I confirm the information provided is accurate and complete"
        checked={confirmAccuracy}
        disabled={disabled}
        onChange={(e) => onConfirmChange(e.target.checked)}
      />
      {errors?.confirm_accuracy ? (
        <p className="text-xs text-[var(--color-error)]">{errors.confirm_accuracy}</p>
      ) : null}
    </div>
  );
}
