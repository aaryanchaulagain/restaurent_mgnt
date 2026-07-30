"use client";

import { use, useMemo } from "react";
import { Breadcrumbs } from "@/components/ui/navigation";
import { PaymentStatusPoller } from "@/features/payments/components/PaymentStatusPoller";
import { useQuery } from "@tanstack/react-query";
import { customerOrderApi } from "@/features/orders/api/order-api";
import { useAuth } from "@/features/auth/hooks/use-auth";

export default function OrderPaymentProcessingPage({
  params,
}: {
  params: Promise<{ orderNumber: string }>;
}) {
  const { orderNumber } = use(params);
  const { user } = useAuth();
  const guestToken = useMemo(() => {
    if (typeof window === "undefined") return null;
    return sessionStorage.getItem(`order_token_${orderNumber}`);
  }, [orderNumber]);

  const orderQuery = useQuery({
    queryKey: ["order-processing", orderNumber],
    queryFn: async () => {
      if (user) {
        const list = await customerOrderApi.list();
        return list.data.orders.find((o) => o.order_number === orderNumber) ?? null;
      }
      if (guestToken) {
        const res = await customerOrderApi.guestTrack(orderNumber, guestToken);
        return res.data.order;
      }
      return null;
    },
    enabled: Boolean(user) || Boolean(guestToken),
  });

  return (
    <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
      <Breadcrumbs
        items={[
          { label: "Home", href: "/" },
          { label: "Order", href: `/orders/${orderNumber}` },
          { label: "Confirming payment" },
        ]}
      />
      <h1 className="mt-4 text-center text-3xl font-bold">Confirming your payment</h1>
      <p className="mt-2 text-center text-sm text-[var(--text-muted)]">
        Order {orderNumber}
      </p>
      <div className="mt-8">
        <PaymentStatusPoller orderNumber={orderNumber} orderPublicId={orderQuery.data?.public_id} />
      </div>
    </main>
  );
}
