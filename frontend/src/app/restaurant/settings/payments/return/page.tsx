"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Skeleton } from "@/components/ui/feedback";
import { restaurantNav } from "@/lib/admin-nav";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantPaymentAccountApi } from "@/features/payments/api/payment-api";

export default function RestaurantPaymentsReturnPage() {
  const profile = useRestaurantProfile();
  const router = useRouter();
  const qc = useQueryClient();

  const refresh = useMutation({
    mutationFn: () => restaurantPaymentAccountApi.refresh(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant", "payment-account"] });
      router.replace("/restaurant/settings/payments");
    },
    onError: () => {
      router.replace("/restaurant/settings/payments");
    },
  });

  useEffect(() => {
    void refresh.mutate();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once on mount after Stripe redirect
  }, []);

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Updating payment status"
      subtitle="Syncing your Stripe Connect account"
    >
      <Skeleton className="h-32 w-full max-w-lg" />
      <p className="mt-4 text-sm text-[var(--text-muted)]">You will be redirected shortly…</p>
    </AdminShell>
  );
}
