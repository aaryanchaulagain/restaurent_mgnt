"use client";

import { useEffect, useMemo, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { businessBranchApi, type BranchDto } from "@/features/business/api/business-branch-api";
import {
  isAggregateBranchContext,
  getBranchContextPublicId,
  setBranchDashboardContext,
} from "@/features/business/lib/branch-context";

const BRANCH_REQUIRED_PREFIXES = [
  "/restaurant/menu",
  "/restaurant/orders",
  "/restaurant/profile",
  "/restaurant/settings",
  "/restaurant/offers",
  "/restaurant/finance",
  "/restaurant/settlements",
  "/restaurant/staff",
  "/restaurant/inventory",
];

function needsSpecificBranch(pathname: string): boolean {
  return BRANCH_REQUIRED_PREFIXES.some(
    (p) => pathname === p || pathname.startsWith(`${p}/`),
  );
}

export function BranchSwitcher() {
  const pathname = usePathname();
  const router = useRouter();
  const qc = useQueryClient();
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const onChange = () => setTick((t) => t + 1);
    window.addEventListener("khana-branch-context-changed", onChange);
    window.addEventListener("storage", onChange);
    return () => {
      window.removeEventListener("khana-branch-context-changed", onChange);
      window.removeEventListener("storage", onChange);
    };
  }, []);

  const contextQuery = useQuery({
    queryKey: ["business-branch-context"],
    queryFn: async () => (await businessBranchApi.context()).data,
  });

  const selectedBranchId = getBranchContextPublicId();
  const aggregate = isAggregateBranchContext();
  void tick;

  const branches = contextQuery.data?.branches ?? [];
  const canAggregate = Boolean(contextQuery.data?.can_aggregate);

  useEffect(() => {
    if (!contextQuery.data || branches.length === 0) return;
    if (selectedBranchId) {
      const stillValid = branches.some((b) => b.public_id === selectedBranchId);
      if (!stillValid) {
        setBranchDashboardContext({
          branchPublicId: null,
          restaurantPublicId: null,
          aggregate: canAggregate,
        });
      }
      return;
    }
    if (aggregate && canAggregate) return;
    const preferred =
      branches.find((b) => b.is_default) ?? branches[0];
    if (preferred) {
      selectBranch(preferred, false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contextQuery.data, selectedBranchId, aggregate, canAggregate]);

  function selectBranch(branch: BranchDto, navigate: boolean) {
    setBranchDashboardContext({
      businessPublicId: branch.business_public_id ?? null,
      branchPublicId: branch.public_id,
      restaurantPublicId: branch.restaurant_public_id ?? null,
      aggregate: false,
    });
    // Drop tenant-scoped caches so previous branch data never flashes.
    void qc.cancelQueries();
    qc.removeQueries({ queryKey: ["restaurant"] });
    qc.removeQueries({ queryKey: ["restaurant-authorization"] });
    qc.invalidateQueries({ queryKey: ["business-branch-context"] });
    if (navigate && pathname.startsWith("/restaurant/branches")) {
      router.push(`/restaurant/branches/${branch.business_public_id}/${branch.public_id}`);
    }
  }

  function selectAggregate() {
    if (!canAggregate) return;
    if (needsSpecificBranch(pathname)) {
      router.push("/restaurant/branches");
    }
    setBranchDashboardContext({
      aggregate: true,
      branchPublicId: null,
    });
  }

  const label = useMemo(() => {
    if (aggregate && canAggregate) return "All Branches";
    const current = branches.find((b) => b.public_id === selectedBranchId);
    return current ? `${current.name}${current.status === "suspended" ? " (Suspended)" : ""}` : "Select branch";
  }, [aggregate, canAggregate, branches, selectedBranchId]);

  if (contextQuery.isLoading) {
    return (
      <div className="rounded-md border border-black/10 bg-white px-3 py-2 text-sm text-black/50">
        Loading branches…
      </div>
    );
  }

  if (branches.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <label className="text-xs font-medium tracking-wide text-black/55 uppercase">
        Branch
      </label>
      <select
        className="min-w-[12rem] rounded-md border border-black/15 bg-white px-3 py-2 text-sm"
        value={aggregate && canAggregate ? "__all__" : (selectedBranchId ?? "")}
        onChange={(e) => {
          const value = e.target.value;
          if (value === "__all__") {
            selectAggregate();
            return;
          }
          const branch = branches.find((b) => b.public_id === value);
          if (branch) selectBranch(branch, true);
        }}
        aria-label="Branch switcher"
      >
        {canAggregate ? <option value="__all__">All Branches</option> : null}
        {branches.map((branch) => (
          <option key={branch.public_id} value={branch.public_id}>
            {branch.name}
            {branch.status === "suspended" ? " (Suspended)" : ""}
            {branch.status === "draft" ? " (Draft)" : ""}
            {branch.status === "paused" ? " (Paused)" : ""}
            {branch.status === "inactive" ? " (Inactive)" : ""}
          </option>
        ))}
      </select>
      <span className="text-xs text-black/45">{label}</span>
    </div>
  );
}
