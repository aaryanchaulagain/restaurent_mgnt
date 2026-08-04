"use client";

import Link from "next/link";
import { useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { EmptyState, Skeleton, Badge } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import {
  publicBusinessApi,
  publicBusinessQueryKeys,
  type PublicBranchDto,
} from "@/features/public-business/api/public-business-api";
import { getBusinessTypeConfig } from "@/features/business/config/business-type-config";

function BranchCard({
  businessSlug,
  branch,
}: {
  businessSlug: string;
  branch: PublicBranchDto;
}) {
  const locality = [branch.address.city, branch.address.state].filter(Boolean).join(", ");

  return (
    <Link
      href={`/businesses/${businessSlug}/branches/${branch.public_id}`}
      className="block rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-5 shadow-[var(--shadow-sm)] transition hover:-translate-y-0.5 hover:border-[rgba(216,102,45,0.35)] hover:shadow-[var(--shadow-md)]"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-[family-name:var(--font-display)] text-2xl text-[var(--text-primary)]">
            {branch.name}
          </h2>
          {locality ? (
            <p className="mt-1 text-sm text-[var(--text-secondary)]">{locality}</p>
          ) : null}
        </div>
        <div className="flex flex-col items-end gap-1">
          {branch.is_temporarily_closed ? (
            <Badge tone="warning">Temporarily closed</Badge>
          ) : branch.is_open_now ? (
            <Badge tone="success">Open</Badge>
          ) : (
            <Badge tone="info">Closed</Badge>
          )}
          {!branch.accepting_orders ? (
            <Badge tone="warning">Not accepting orders</Badge>
          ) : null}
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-2 text-xs text-[var(--text-muted)]">
        {branch.capabilities.pickup_enabled ? <span>Pickup</span> : null}
        {branch.capabilities.delivery_enabled ? <span>Delivery</span> : null}
        {branch.is_default ? <span>Primary</span> : null}
      </div>
    </Link>
  );
}

export function LiveBusinessPage({ businessSlug }: { businessSlug: string }) {
  const query = useQuery({
    queryKey: publicBusinessQueryKeys.business(businessSlug),
    queryFn: async () => (await publicBusinessApi.getBusiness(businessSlug)).data,
    retry: false,
  });

  const copy = useMemo(
    () => getBusinessTypeConfig(query.data?.business.business_type),
    [query.data?.business.business_type],
  );

  if (query.isLoading) {
    return (
      <main className="mx-auto max-w-5xl px-4 py-12">
        <Skeleton className="h-40 w-full" />
      </main>
    );
  }

  if (query.isError || !query.data) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-20">
        <EmptyState title="Business not found" description="This business is unavailable." />
      </main>
    );
  }

  const { business, branches, preferred_branch_public_id, branch_count } = query.data;

  return (
    <main className="min-h-screen bg-[var(--surface-page)]">
      <section className="border-b border-[var(--border-subtle)] bg-[linear-gradient(160deg,#1a120c_0%,#3d2418_55%,#6b3a22_100%)] px-4 py-14 text-white sm:px-6">
        <div className="mx-auto max-w-5xl">
          <p className="text-xs font-semibold tracking-[0.2em] text-white/60 uppercase">
            {copy.label}
          </p>
          <h1 className="mt-3 font-[family-name:var(--font-display)] text-4xl sm:text-5xl">
            {business.name}
          </h1>
          {business.description ? (
            <p className="mt-3 max-w-2xl text-sm text-white/75 sm:text-base">{business.description}</p>
          ) : null}
        </div>
      </section>

      <section className="mx-auto max-w-5xl px-4 py-10 sm:px-6">
        {branch_count === 0 ? (
          <EmptyState
            title="No locations available"
            description="This business has no public locations right now."
          />
        ) : null}

        {branch_count === 1 && preferred_branch_public_id ? (
          <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-[var(--text-primary)]">Location</h2>
                <p className="text-sm text-[var(--text-secondary)]">
                  Browse the catalogue for this location.
                </p>
              </div>
              <Link href={`/businesses/${business.slug}/branches/${preferred_branch_public_id}`}>
                <Button type="button">View catalogue</Button>
              </Link>
            </div>
            <BranchCard businessSlug={business.slug} branch={branches[0]} />
          </div>
        ) : null}

        {branch_count > 1 ? (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-semibold text-[var(--text-primary)]">Choose a location</h2>
              <p className="text-sm text-[var(--text-secondary)]">
                Each location has its own catalogue and availability.
              </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              {branches.map((branch) => (
                <BranchCard key={branch.public_id} businessSlug={business.slug} branch={branch} />
              ))}
            </div>
          </div>
        ) : null}
      </section>
    </main>
  );
}
