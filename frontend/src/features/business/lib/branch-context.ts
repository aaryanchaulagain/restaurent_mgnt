const BRANCH_KEY = "khana_branch_context_public_id";
const BUSINESS_KEY = "khana_business_context_public_id";
const AGGREGATE_KEY = "khana_branch_aggregate";
const RESTAURANT_KEY = "suvakamana_restaurant_context_public_id";

export type BranchDashboardContext = {
  businessPublicId: string | null;
  branchPublicId: string | null;
  restaurantPublicId: string | null;
  aggregate: boolean;
};

export function getBranchContextPublicId(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(BRANCH_KEY);
}

export function getBusinessContextPublicId(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(BUSINESS_KEY);
}

export function isAggregateBranchContext(): boolean {
  if (typeof window === "undefined") return false;
  return window.localStorage.getItem(AGGREGATE_KEY) === "1";
}

export function setBranchDashboardContext(ctx: {
  businessPublicId?: string | null;
  branchPublicId?: string | null;
  restaurantPublicId?: string | null;
  aggregate?: boolean;
}): void {
  if (typeof window === "undefined") return;

  if (ctx.aggregate) {
    window.localStorage.setItem(AGGREGATE_KEY, "1");
    window.localStorage.removeItem(BRANCH_KEY);
  } else {
    window.localStorage.removeItem(AGGREGATE_KEY);
    if (ctx.branchPublicId) {
      window.localStorage.setItem(BRANCH_KEY, ctx.branchPublicId);
    } else if (ctx.branchPublicId === null) {
      window.localStorage.removeItem(BRANCH_KEY);
    }
  }

  if (ctx.businessPublicId) {
    window.localStorage.setItem(BUSINESS_KEY, ctx.businessPublicId);
  } else if (ctx.businessPublicId === null) {
    window.localStorage.removeItem(BUSINESS_KEY);
  }

  if (ctx.restaurantPublicId) {
    window.localStorage.setItem(RESTAURANT_KEY, ctx.restaurantPublicId);
  } else if (ctx.restaurantPublicId === null) {
    window.localStorage.removeItem(RESTAURANT_KEY);
  }

  window.dispatchEvent(new Event("khana-branch-context-changed"));
}

export function clearBranchDashboardContext(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(BRANCH_KEY);
  window.localStorage.removeItem(BUSINESS_KEY);
  window.localStorage.removeItem(AGGREGATE_KEY);
  window.dispatchEvent(new Event("khana-branch-context-changed"));
}

export function readBranchDashboardContext(): BranchDashboardContext {
  return {
    businessPublicId: getBusinessContextPublicId(),
    branchPublicId: getBranchContextPublicId(),
    restaurantPublicId:
      typeof window !== "undefined" ? window.localStorage.getItem(RESTAURANT_KEY) : null,
    aggregate: isAggregateBranchContext(),
  };
}
