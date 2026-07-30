"use client";

import { useEffect, useState } from "react";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/feedback";
import { restaurantNav } from "@/lib/admin-nav";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantPaymentAccountApi } from "@/features/payments/api/payment-api";
import { ApiError } from "@/lib/api/client";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

export default function RestaurantPaymentsRefreshPage() {
  const profile = useRestaurantProfile();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    void restaurantPaymentAccountApi
      .onboardingLink()
      .then((res) => {
        if (cancelled) return;
        if (res.data.url) window.location.href = res.data.url;
        else setLoading(false);
      })
      .catch((e: unknown) => {
        if (cancelled) return;
        setError(e instanceof ApiError ? paymentErrorMessage(e.code, e.message) : "Could not create onboarding link.");
        setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Continue Stripe onboarding"
      subtitle="Regenerating a secure onboarding link"
    >
      {loading ? <Skeleton className="h-32 w-full max-w-lg" /> : null}
      {error ? (
        <div className="space-y-3">
          <p className="text-sm text-red-600">{error}</p>
          <Button onClick={() => window.location.assign("/restaurant/settings/payments")}>
            Back to payments settings
          </Button>
        </div>
      ) : null}
    </AdminShell>
  );
}
