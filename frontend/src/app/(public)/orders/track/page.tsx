"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { Breadcrumbs } from "@/components/ui/navigation";
import { customerOrderApi } from "@/features/orders/api/order-api";

export default function OrderTrackPage() {
  const router = useRouter();
  const [orderNumber, setOrderNumber] = useState("");
  const [token, setToken] = useState("");
  const [error, setError] = useState("");

  const trackMut = useMutation({
    mutationFn: async () => {
      if (!orderNumber.trim() || !token.trim()) throw new Error("Both fields required");
      const res = await customerOrderApi.guestTrack(orderNumber.trim(), token.trim());
      return res.data.order;
    },
    onSuccess: (order) => {
      sessionStorage.setItem(`order_token_${order.order_number}`, token.trim());
      router.push(`/orders/${order.order_number}`);
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Order not found. Check your details."),
  });

  return (
    <main className="mx-auto max-w-lg px-4 py-8 sm:px-6">
      <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Track order" }]} />
      <h1 className="mt-4 text-3xl font-bold">Track your order</h1>
      <p className="mt-2 text-sm text-[var(--text-secondary)]">Enter your order number and the tracking token from your confirmation email.</p>

      <form
        className="mt-8 space-y-4 rounded-xl border bg-white p-6 shadow-md"
        onSubmit={(e) => {
          e.preventDefault();
          setError("");
          if (!orderNumber.trim() || !token.trim()) {
            setError("Both fields required. Order number alone is not enough.");
            return;
          }
          trackMut.mutate();
        }}
      >
        <Field label="Order number" htmlFor="orderNumber">
          <Input id="orderNumber" value={orderNumber} onChange={(e) => setOrderNumber(e.target.value)} placeholder="SVK-20260729-000001" />
        </Field>
        <Field label="Tracking token" htmlFor="token">
          <Input id="token" value={token} onChange={(e) => setToken(e.target.value)} placeholder="Your secure token" />
        </Field>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button type="submit" className="w-full" loading={trackMut.isPending}>Track order</Button>
      </form>

      <p className="mt-4 text-center text-sm text-[var(--text-muted)]">
        Have an account?{" "}
        <Link href="/profile/orders" className="font-semibold text-[var(--color-burnt-orange)]">View past orders</Link>
      </p>
    </main>
  );
}
