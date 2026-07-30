"use client";

import { Radio } from "@/components/ui/forms";

export type CheckoutPaymentMethod = "cash" | "online_card";

type Props = {
  value: CheckoutPaymentMethod;
  onChange: (value: CheckoutPaymentMethod) => void;
  onlineDisabled?: boolean;
  onlineDisabledReason?: string;
};

export function PaymentMethodSelector({
  value,
  onChange,
  onlineDisabled,
  onlineDisabledReason,
}: Props) {
  return (
    <div className="space-y-2">
      <Radio
        name="payment_method"
        label="Pay with cash on pickup or delivery"
        checked={value === "cash"}
        onChange={() => onChange("cash")}
      />
      <Radio
        name="payment_method"
        label="Pay online with card"
        checked={value === "online_card"}
        onChange={() => onChange("online_card")}
        disabled={onlineDisabled}
      />
      {onlineDisabled && onlineDisabledReason ? (
        <p className="text-xs text-[var(--text-muted)]">{onlineDisabledReason}</p>
      ) : null}
      {value === "online_card" ? (
        <p className="text-xs text-[var(--text-muted)]">
          You will enter card details on the next step. Payment is confirmed only after our server verifies it.
        </p>
      ) : null}
    </div>
  );
}
