"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { apiGet } from "@/lib/api/client";
import { getBranchContextPublicId } from "@/features/business/lib/branch-context";

export type BranchAuthorization = {
  business: {
    public_id: string | null;
    business_type: string;
  };
  branch: {
    public_id: string;
    name: string;
  };
  role: string | null;
  permissions: string[];
};

export function useBranchAuthorization() {
  const [branchId, setBranchId] = useState<string | null>(() => getBranchContextPublicId());

  useEffect(() => {
    const sync = () => setBranchId(getBranchContextPublicId());
    window.addEventListener("khana-branch-context-changed", sync);
    window.addEventListener("storage", sync);
    return () => {
      window.removeEventListener("khana-branch-context-changed", sync);
      window.removeEventListener("storage", sync);
    };
  }, []);

  return useQuery({
    queryKey: ["restaurant-authorization", branchId],
    enabled: Boolean(branchId),
    queryFn: async () => (await apiGet<BranchAuthorization>("/api/v1/restaurant/authorization")).data,
    staleTime: 30_000,
  });
}

export function hasEffectivePermission(
  permissions: string[] | undefined | null,
  required: string | string[],
): boolean {
  if (!permissions) return false;
  const list = Array.isArray(required) ? required : [required];
  return list.some((p) => permissions.includes(p));
}
