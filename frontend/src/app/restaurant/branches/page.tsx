"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { businessBranchApi } from "@/features/business/api/business-branch-api";
import { setBranchDashboardContext } from "@/features/business/lib/branch-context";

export default function BranchesPage() {
  const { items: navItems, portalLabel: shellPortalLabel } = useRestaurantShell();
  const context = useQuery({
    queryKey: ["business-branch-context"],
    queryFn: async () => (await businessBranchApi.context()).data,
  });

  const businesses = context.data?.businesses ?? [];
  const branches = context.data?.branches ?? [];
  const primaryBusiness = businesses[0];
  const canManage = Boolean(context.data?.can_aggregate);

  return (
    <AdminShell
      brand={primaryBusiness?.name ?? "Business"}
      portalLabel={shellPortalLabel}
      items={navItems}
      title="Branches"
      subtitle="Locations for this business"
      actions={
        canManage && primaryBusiness ? (
          <Link href={`/restaurant/branches/new?business=${primaryBusiness.public_id}`}>
            <Button size="sm">Add branch</Button>
          </Link>
        ) : null
      }
    >
      {context.isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {!context.isLoading && branches.length === 0 ? (
        <EmptyState title="No branches" description="Create your first location to start operating." />
      ) : null}

      {context.data?.can_aggregate ? (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {Object.entries(
            branches.reduce<Record<string, number>>(
              (acc, b) => {
                acc[b.status] = (acc[b.status] ?? 0) + 1;
                return acc;
              },
              { total: branches.length },
            ),
          ).map(([key, value]) => (
            <div
              key={key}
              className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-white px-4 py-3"
            >
              <p className="text-xs tracking-wide text-black/50 uppercase">{key}</p>
              <p className="mt-1 text-2xl font-semibold">{value}</p>
            </div>
          ))}
        </div>
      ) : null}

      <ul className="space-y-3">
        {branches.map((branch) => (
          <li
            key={branch.public_id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4"
          >
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-lg font-medium">{branch.name}</p>
                <Badge>{branch.status_label}</Badge>
                {branch.is_default ? <Badge>Default</Badge> : null}
              </div>
              <p className="mt-1 text-sm text-black/55">
                {branch.code ? `${branch.code} · ` : ""}
                {[branch.city, branch.state].filter(Boolean).join(", ") || "Address pending"}
              </p>
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => {
                  setBranchDashboardContext({
                    businessPublicId: branch.business_public_id ?? primaryBusiness?.public_id ?? null,
                    branchPublicId: branch.public_id,
                    restaurantPublicId: branch.restaurant_public_id ?? null,
                    aggregate: false,
                  });
                }}
              >
                Switch to
              </Button>
              <Link
                href={`/restaurant/branches/${branch.business_public_id ?? primaryBusiness?.public_id}/${branch.public_id}`}
              >
                <Button size="sm">Settings</Button>
              </Link>
            </div>
          </li>
        ))}
      </ul>
    </AdminShell>
  );
}
