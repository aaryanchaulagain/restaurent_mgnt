"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/client";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { orderErrorMessage } from "@/features/orders/utils/order-errors";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

type Props = {
  quotePublicId: string;
  customerName: string;
  customerEmail: string;
  customerPhone?: string;
  pickupInstructions?: string;
  deliveryInstructions?: string;
  customerNotes?: string;
  contactlessDelivery?: boolean;
  paymentMethod?: "cash" | "online_card";
  disabled?: boolean;
  className?: string;
};

function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `idem-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function PlaceOrderButton({
  quotePublicId,
  customerName,
  customerEmail,
  customerPhone,
  pickupInstructions,
  deliveryInstructions,
  customerNotes,
  contactlessDelivery,
  paymentMethod = "cash",
  disabled,
  className,
}: Props) {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const idempotencyKeyRef = useRef<string | null>(null);
  const inFlightRef = useRef(false);

  const place = async () => {
    if (inFlightRef.current || disabled) return;
    inFlightRef.current = true;
    setLoading(true);
    setError(null);
    if (!idempotencyKeyRef.current) {
      idempotencyKeyRef.current = newIdempotencyKey();
    }
    try {
      const res = await customerOrderApi.place({
        checkout_quote_public_id: quotePublicId,
        idempotency_key: idempotencyKeyRef.current,
        payment_method: paymentMethod,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: customerPhone,
        pickup_instructions: pickupInstructions,
        delivery_instructions: deliveryInstructions,
        customer_notes: customerNotes,
        contactless_delivery: contactlessDelivery,
      });
      const order = res.data.order;
      const payment = (res.data as { payment?: { client_secret?: string; publishable_key?: string } }).payment;
      if (order.guest_access_token) {
        sessionStorage.setItem(`order_token_${order.order_number}`, order.guest_access_token);
      }
      if (payment?.client_secret) {
        sessionStorage.setItem(`payment_cs_${order.order_number}`, payment.client_secret);
        if (payment.publishable_key) {
          sessionStorage.setItem(`payment_pk_${order.order_number}`, payment.publishable_key);
        }
        router.push(`/orders/${order.order_number}/payment`);
        return;
      }
      router.push(`/orders/${order.order_number}`);
    } catch (e: unknown) {
      if (e instanceof ApiError) {
        const paymentMsg = paymentErrorMessage(e.code, "");
        const fallback = orderErrorMessage(e.code, e.message);
        setError(
          e.code && paymentMsg !== "Payment request could not be completed." ? paymentMsg : fallback,
        );
      } else {
        setError("Could not place order.");
      }
      inFlightRef.current = false;
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={className}>
      {error ? <p className="mb-2 text-sm text-red-600">{error}</p> : null}
      <Button className="w-full" size="lg" disabled={disabled || loading} onClick={() => void place()}>
        {loading
          ? "Placing order…"
          : paymentMethod === "online_card"
            ? "Place order & pay online"
            : "Place order (cash)"}
      </Button>
      <p className="mt-2 text-center text-xs text-[var(--text-muted)]">
        {paymentMethod === "online_card"
          ? "Card details are collected on the next step after your order is created."
          : "Pay with cash when you pick up or receive delivery."}
      </p>
    </div>
  );
}
