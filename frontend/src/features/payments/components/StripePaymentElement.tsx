"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { loadStripe, type StripeElementsOptions } from "@stripe/stripe-js";
import { Button } from "@/components/ui/button";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

type InnerProps = {
  orderNumber: string;
  returnUrl: string;
};

function PaymentForm({ orderNumber, returnUrl }: InnerProps) {
  const stripe = useStripe();
  const elements = useElements();
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (!stripe || !elements || submitting) return;
    setSubmitting(true);
    setError(null);
    const result = await stripe.confirmPayment({
      elements,
      confirmParams: { return_url: returnUrl },
      redirect: "if_required",
    });
    if (result.error) {
      setError(result.error.message ?? paymentErrorMessage("PAYMENT_FAILED", "Payment could not be completed."));
      setSubmitting(false);
      return;
    }
    router.push(`/orders/${orderNumber}/payment/processing`);
  };

  return (
    <div className="space-y-4">
      <PaymentElement options={{ layout: "tabs" }} />
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <Button className="w-full" size="lg" disabled={!stripe || submitting} onClick={() => void submit()}>
        {submitting ? "Confirming with your bank…" : "Pay now"}
      </Button>
      <p className="text-center text-xs text-[var(--text-muted)]">
        We will confirm payment on our servers before marking your order as paid.
      </p>
    </div>
  );
}

type Props = {
  orderNumber: string;
  clientSecret: string;
  publishableKey?: string | null;
};

export function StripePaymentElement({ orderNumber, clientSecret, publishableKey }: Props) {
  const pk =
    process.env.NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY?.trim() ||
    publishableKey?.trim() ||
    "";

  const promise = useMemo(() => (pk ? loadStripe(pk) : null), [pk]);

  if (!pk || !promise) {
    return (
      <p className="text-sm text-red-600">
        Online payments are not configured. Please contact support or choose cash at checkout.
      </p>
    );
  }

  const options: StripeElementsOptions = {
    clientSecret,
    appearance: {
      theme: "stripe",
      variables: {
        colorPrimary: "#c45c26",
        borderRadius: "8px",
      },
    },
  };

  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const returnUrl = `${origin}/orders/${orderNumber}/payment/processing`;

  return (
    <Elements stripe={promise} options={options}>
      <PaymentForm orderNumber={orderNumber} returnUrl={returnUrl} />
    </Elements>
  );
}
