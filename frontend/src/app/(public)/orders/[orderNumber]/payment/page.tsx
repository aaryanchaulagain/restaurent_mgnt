"use client";

import Link from "next/link";
import { use, useMemo } from "react";
import { Breadcrumbs } from "@/components/ui/navigation";
import { EmptyState } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { StripePaymentElement } from "@/features/payments/components/StripePaymentElement";
import { formatCents } from "@/lib/utils";
import { useQuery } from "@tanstack/react-query";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { useAuth } from "@/features/auth/hooks/use-auth";

function readSessionPayment(orderNumber: string) {
  if (typeof window === "undefined") return { clientSecret: null as string | null, publishableKey: null as string | null };
  return {
    clientSecret: sessionStorage.getItem(`payment_cs_${orderNumber}`),
    publishableKey: sessionStorage.getItem(`payment_pk_${orderNumber}`),
  };
}

export default function OrderPaymentPage({ params }: { params: Promise<{ orderNumber: string }> }) {
  const { orderNumber } = use(params);
  const { user } = useAuth();
  const guestToken = useMemo(() => {
    if (typeof window === "undefined") return null;
    return sessionStorage.getItem(`order_token_${orderNumber}`);
  }, [orderNumber]);

  const { clientSecret, publishableKey } = useMemo(
    () => readSessionPayment(orderNumber),
    [orderNumber],
  );

  const orderQuery = useQuery({
    queryKey: ["order-payment-context", orderNumber, Boolean(user), Boolean(guestToken)],
    queryFn: async () => {
      if (user) {
        const list = await customerOrderApi.list();
        const found = list.data.orders.find((o) => o.order_number === orderNumber);
        if (!found) throw new Error("Not found");
        return found;
      }
      if (guestToken) {
        const res = await customerOrderApi.guestTrack(orderNumber, guestToken);
        return res.data.order;
      }
      return null;
    },
    enabled: Boolean(user) || Boolean(guestToken),
  });

  const order = orderQuery.data;

  if (!user && !guestToken) {
    return (
      <main className="mx-auto max-w-lg px-4 py-16 text-center">
        <EmptyState
          title="Sign in to complete payment"
          description="Use the guest link from your order confirmation if you checked out as a guest."
          action={
            <Link href={`/orders/${orderNumber}`}>
              <Button variant="outline">Back to order</Button>
            </Link>
          }
        />
      </main>
    );
  }

  if (!clientSecret) {
    return (
      <main className="mx-auto max-w-lg px-4 py-16 text-center">
        <EmptyState
          title="Payment session expired"
          description="Start checkout again or open your order to retry payment when available."
          action={
            <Link href={`/orders/${orderNumber}`}>
              <Button>View order</Button>
            </Link>
          }
        />
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-lg px-4 py-8 sm:px-6">
      <Breadcrumbs
        items={[
          { label: "Home", href: "/" },
          { label: "Order", href: `/orders/${orderNumber}` },
          { label: "Payment" },
        ]}
      />
      <h1 className="mt-4 text-3xl font-bold">Complete payment</h1>
      {order ? (
        <p className="mt-1 text-sm text-[var(--text-muted)]">
          Order {order.order_number} · {formatCents(order.total_cents)}
        </p>
      ) : null}
      <div className="mt-6 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
        <StripePaymentElement
          orderNumber={orderNumber}
          clientSecret={clientSecret}
          publishableKey={publishableKey}
        />
      </div>
    </main>
  );
}
