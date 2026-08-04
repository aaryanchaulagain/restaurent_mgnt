import { describe, expect, it } from "vitest";
import { publicBusinessQueryKeys } from "@/features/public-business/api/public-business-api";

describe("public business query keys", () => {
  it("scopes cache by business and branch", () => {
    expect(publicBusinessQueryKeys.menu("acme", "branch-a")).toEqual([
      "public-business-branch-menu",
      "acme",
      "branch-a",
    ]);
    expect(publicBusinessQueryKeys.menu("acme", "branch-a")).not.toEqual(
      publicBusinessQueryKeys.menu("acme", "branch-b"),
    );
    expect(publicBusinessQueryKeys.branch("acme", "b1")).not.toEqual(
      publicBusinessQueryKeys.business("acme"),
    );
  });

  it("scopes recommendation cache without raw coordinates", () => {
    expect(publicBusinessQueryKeys.recommendations("acme", "delivery", "addr-1", null)).toEqual([
      "branch-recommendations",
      "acme",
      "delivery",
      "addr-1",
      null,
    ]);
    expect(publicBusinessQueryKeys.recommendations("acme", "delivery", null, "4000")).not.toEqual(
      publicBusinessQueryKeys.recommendations("acme", "pickup", null, "4000"),
    );
  });
});
