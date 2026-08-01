"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/feedback";
import { customerPaymentApi } from "@/features/payments/api/payment-api";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

const POLL_MS = 4000;
const TIMEOUT_MS = 120_000;

type PollerProps = {
  orderNumber: string;
  orderPublicId?: string | null;
};

type PollState = "confirming" | "paid" | "failed" | "requires_action" | "timeout";

function mapStatus(status: string | undefined): PollState {
  if (!status) return "confirming";
  if (status === "paid") return "paid";
  if (status === "failed" || status === "cancelled") return "failed";
  if (status === "requires_action") return "requires_action";
  if (status === "processing" || status === "pending") return "confirming";
  return "confirming";
}

export function PaymentStatusPoller({ orderNumber, orderPublicId }: PollerProps) {
  const { user } = useAuth();
  const guestToken = useMemo(() => {
    if (typeof window === "undefined") return null;
    return sessionStorage.getItem(`order_token_${orderNumber}`);
  }, [orderNumber]);

  const [state, setState] = useState<PollState>("confirming");
  const [errorCode, setErrorCode] = useState<string | null>(null);
  const [resolvedPublicId, setResolvedPublicId] = useState(orderPublicId ?? null);

  useEffect(() => {
    let cancelled = false;
    const started = Date.now();

    const tick = async () => {
      if (cancelled) return;
      if (Date.now() - started > TIMEOUT_MS) {
        setState("timeout");
        return;
      }

      try {
        if (user && resolvedPublicId) {
          const res = await customerPaymentApi.getForOrder(resolvedPublicId);
          const payment = res.data.payment;
          if (payment?.last_error_code) setErrorCode(payment.last_error_code);
          const next = mapStatus(payment?.status);
          setState(next);
          if (next === "paid" || next === "failed" || next === "requires_action") return;
        } else if (guestToken) {
          const res = await customerOrderApi.guestTrack(orderNumber, guestToken);
          const o = res.data.order;
          if (!resolvedPublicId) setResolvedPublicId(o.public_id);
          const next = mapStatus(o.payment_status);
          setState(next);
          if (next === "paid" || next === "failed") return;
        } else if (user && !resolvedPublicId) {
          const list = await customerOrderApi.list();
          const found = list.data.orders.find((o) => o.order_number === orderNumber);
          if (found) setResolvedPublicId(found.public_id);
        }
      } catch {
        // keep polling
      }

      if (!cancelled) {
        window.setTimeout(tick, POLL_MS);
      }
    };

    void tick();
    return () => {
      cancelled = true;
    };
  }, [user, guestToken, orderNumber, resolvedPublicId]);

  const label =
    state === "paid"
      ? "Paid"
      : state === "failed"
        ? "Failed"
        : state === "requires_action"
          ? "Requires action"
          : state === "timeout"
            ? "Still confirming"
            : "Confirming…";

  const tone =
    state === "paid" ? "success" : state === "failed" ? "error" : state === "requires_action" ? "warning" : "info";

  return (
    <div className="mx-auto max-w-md rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-8 text-center shadow-[var(--shadow-sm)]">
      <Badge tone={tone} className="text-sm">
        {label}
      </Badge>
      {state === "confirming" ? (
        <p className="mt-4 text-sm text-[var(--text-secondary)]">
          Waiting for payment confirmation from our payment provider. This usually takes a few seconds.
        </p>
      ) : null}
      {state === "requires_action" ? (
        <p className="mt-4 text-sm text-[var(--text-secondary)]">
          Your bank needs additional verification. Return to payment to complete authentication.
        </p>
      ) : null}
      {state === "failed" ? (
        <p className="mt-4 text-sm text-red-600">
          {paymentErrorMessage(errorCode, "Payment did not complete. You can try again from your order.")}
        </p>
      ) : null}
      {state === "timeout" ? (
        <p className="mt-4 text-sm text-[var(--text-secondary)]">
          Confirmation is taking longer than usual. Check your order page for the latest payment status.
        </p>
      ) : null}
      {state === "paid" ? (
        <p className="mt-4 text-sm text-green-800">Your payment was confirmed. The restaurant has been notified.</p>
      ) : null}
      <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        {state === "requires_action" ? (
          <Link href={`/orders/${orderNumber}/payment`}>
            <Button>Return to payment</Button>
          </Link>
        ) : null}
        <Link href={`/orders/${orderNumber}`}>
          <Button variant={state === "paid" ? "primary" : "outline"}>View order</Button>
        </Link>
      </div>
    </div>
  );
}
