"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/feedback";
import { restaurantNav } from "@/lib/admin-nav";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";
import { restaurantPaymentAccountApi } from "@/features/payments/api/payment-api";
import { PaymentAccountStatusCard } from "@/features/payments/components/PaymentAccountStatusCard";
import { ApiError } from "@/lib/api/client";
import { paymentErrorMessage } from "@/features/payments/utils/payment-errors";

export default function RestaurantPaymentsSettingsPage() {
  const profile = useRestaurantProfile();
  const qc = useQueryClient();
  const [actionError, setActionError] = useState<string | null>(null);

  const accountQuery = useQuery({
    queryKey: ["restaurant", "payment-account"],
    queryFn: async () => (await restaurantPaymentAccountApi.get()).data.payment_account,
  });

  const createAccount = useMutation({
    mutationFn: () => restaurantPaymentAccountApi.create(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant", "payment-account"] });
      setActionError(null);
    },
    onError: (e: unknown) => {
      setActionError(e instanceof ApiError ? paymentErrorMessage(e.code, e.message) : "Could not create account.");
    },
  });

  const onboardingLink = useMutation({
    mutationFn: () => restaurantPaymentAccountApi.onboardingLink(),
    onSuccess: (res) => {
      setActionError(null);
      if (res.data.url) window.location.href = res.data.url;
    },
    onError: (e: unknown) => {
      setActionError(e instanceof ApiError ? paymentErrorMessage(e.code, e.message) : "Could not start onboarding.");
    },
  });

  const refresh = useMutation({
    mutationFn: () => restaurantPaymentAccountApi.refresh(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant", "payment-account"] });
      setActionError(null);
    },
    onError: (e: unknown) => {
      setActionError(e instanceof ApiError ? paymentErrorMessage(e.code, e.message) : "Could not refresh status.");
    },
  });

  const account = accountQuery.data;
  const isFirstParty = account?.ownership_type === "first_party";
  const needsSetup =
    !isFirstParty &&
    (account?.onboarding_status === "not_started" || !account?.charges_enabled);

  return (
    <AdminShell
      brand={profile.data?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Payments"
      subtitle="Connect Stripe to accept online card payments"
      actions={
        !isFirstParty ? (
          <div className="flex flex-wrap gap-2">
            {needsSetup ? (
              <Button
                onClick={() =>
                  void (account?.onboarding_status === "not_started"
                    ? createAccount.mutate()
                    : onboardingLink.mutate())
                }
                disabled={createAccount.isPending || onboardingLink.isPending}
              >
                {account?.onboarding_status === "not_started" ? "Set up payouts" : "Continue onboarding"}
              </Button>
            ) : (
              <Button
                variant="outline"
                onClick={() => void onboardingLink.mutate()}
                disabled={onboardingLink.isPending}
              >
                Update payout details
              </Button>
            )}
            <Button variant="secondary" onClick={() => void refresh.mutate()} disabled={refresh.isPending}>
              Refresh status
            </Button>
          </div>
        ) : null
      }
    >
      {actionError ? <p className="mb-4 text-sm text-red-600">{actionError}</p> : null}
      {accountQuery.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : account ? (
        <PaymentAccountStatusCard account={account} />
      ) : (
        <p className="text-sm text-[var(--text-muted)]">Unable to load payment account.</p>
      )}
    </AdminShell>
  );
}
