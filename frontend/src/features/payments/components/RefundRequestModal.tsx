"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/overlay";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/forms";
import { formatCents } from "@/lib/utils";

const REASON_OPTIONS = [
  { value: "requested_by_customer", label: "Customer request" },
  { value: "duplicate", label: "Duplicate charge" },
  { value: "order_issue", label: "Order issue" },
  { value: "other", label: "Other" },
];

export type RefundSubmitPayload = {
  amount_cents: number;
  reason_category: string;
  customer_reason?: string;
  internal_note?: string;
  confirm: true;
};

type Props = {
  open: boolean;
  onClose: () => void;
  maxAmountCents: number;
  currencyLabel?: string;
  title?: string;
  submitting?: boolean;
  onSubmit: (payload: RefundSubmitPayload) => void | Promise<void>;
};

export function RefundRequestModal({
  open,
  onClose,
  maxAmountCents,
  currencyLabel = "AUD",
  title = "Issue refund",
  submitting,
  onSubmit,
}: Props) {
  const [amountDollars, setAmountDollars] = useState("");
  const [reasonCategory, setReasonCategory] = useState(REASON_OPTIONS[0].value);
  const [customerReason, setCustomerReason] = useState("");
  const [internalNote, setInternalNote] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [validationError, setValidationError] = useState<string | null>(null);

  const reset = () => {
    setAmountDollars("");
    setReasonCategory(REASON_OPTIONS[0].value);
    setCustomerReason("");
    setInternalNote("");
    setConfirmed(false);
    setValidationError(null);
  };

  const handleClose = () => {
    reset();
    onClose();
  };

  const submit = async () => {
    setValidationError(null);
    const parsed = Number.parseFloat(amountDollars.replace(/,/g, ""));
    if (!Number.isFinite(parsed) || parsed <= 0) {
      setValidationError("Enter a valid refund amount.");
      return;
    }
    const amount_cents = Math.round(parsed * 100);
    if (amount_cents > maxAmountCents) {
      setValidationError(`Amount cannot exceed ${formatCents(maxAmountCents)} ${currencyLabel}.`);
      return;
    }
    if (!reasonCategory.trim()) {
      setValidationError("Select a reason category.");
      return;
    }
    if (!confirmed) {
      setValidationError("Confirm that you intend to issue this refund.");
      return;
    }
    await onSubmit({
      amount_cents,
      reason_category: reasonCategory,
      customer_reason: customerReason.trim() || undefined,
      internal_note: internalNote.trim() || undefined,
      confirm: true,
    });
    reset();
  };

  return (
    <Modal open={open} onClose={handleClose} title={title} className="sm:max-w-md">
      <p className="text-sm text-[var(--text-muted)]">
        Maximum refundable: {formatCents(maxAmountCents)} {currencyLabel}
      </p>
      <div className="mt-4 space-y-4">
        <Field label="Refund amount" htmlFor="refund-amount">
          <Input
            id="refund-amount"
            inputMode="decimal"
            placeholder="0.00"
            value={amountDollars}
            onChange={(e) => setAmountDollars(e.target.value)}
          />
        </Field>
        <Field label="Reason" htmlFor="refund-reason">
          <Select id="refund-reason" value={reasonCategory} onChange={(e) => setReasonCategory(e.target.value)}>
            {REASON_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Customer-facing reason (optional)" htmlFor="refund-customer-reason">
          <Textarea
            id="refund-customer-reason"
            value={customerReason}
            onChange={(e) => setCustomerReason(e.target.value)}
            rows={2}
          />
        </Field>
        <Field label="Internal note (optional)" htmlFor="refund-internal">
          <Textarea id="refund-internal" value={internalNote} onChange={(e) => setInternalNote(e.target.value)} rows={2} />
        </Field>
        <Checkbox
          label="I confirm this refund should be processed"
          checked={confirmed}
          onChange={(e) => setConfirmed(e.target.checked)}
        />
        {validationError ? <p className="text-sm text-red-600">{validationError}</p> : null}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={handleClose} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} disabled={submitting}>
            {submitting ? "Submitting…" : "Submit refund"}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
