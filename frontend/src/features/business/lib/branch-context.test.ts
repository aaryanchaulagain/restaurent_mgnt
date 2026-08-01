import { describe, expect, it, beforeEach, afterEach } from "vitest";
import {
  clearBranchDashboardContext,
  getBranchContextPublicId,
  isAggregateBranchContext,
  readBranchDashboardContext,
  setBranchDashboardContext,
} from "@/features/business/lib/branch-context";

describe("branch-context storage", () => {
  beforeEach(() => {
    clearBranchDashboardContext();
    window.localStorage.clear();
  });

  afterEach(() => {
    window.localStorage.clear();
  });

  it("stores branch and restaurant context together", () => {
    setBranchDashboardContext({
      businessPublicId: "biz-1",
      branchPublicId: "branch-1",
      restaurantPublicId: "rest-1",
      aggregate: false,
    });

    expect(getBranchContextPublicId()).toBe("branch-1");
    expect(readBranchDashboardContext()).toEqual({
      businessPublicId: "biz-1",
      branchPublicId: "branch-1",
      restaurantPublicId: "rest-1",
      aggregate: false,
    });
    expect(isAggregateBranchContext()).toBe(false);
  });

  it("clears branch id when aggregate is selected", () => {
    setBranchDashboardContext({
      branchPublicId: "branch-1",
      restaurantPublicId: "rest-1",
    });
    setBranchDashboardContext({ aggregate: true });

    expect(isAggregateBranchContext()).toBe(true);
    expect(getBranchContextPublicId()).toBeNull();
  });

  it("clears invalid context helper", () => {
    setBranchDashboardContext({ branchPublicId: "branch-1" });
    clearBranchDashboardContext();
    expect(getBranchContextPublicId()).toBeNull();
    expect(isAggregateBranchContext()).toBe(false);
  });
});
