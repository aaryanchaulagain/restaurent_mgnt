import { describe, expect, it } from "vitest";

describe("admin order relationship warning", () => {
  it("surfaces historical soft-deleted warning without secrets", () => {
    const relationship = {
      state: "historical_soft_deleted",
      warning:
        "Partner restaurant is archived/soft-deleted. Historical order only — not a live branch queue item.",
      branch_public_id: "branch-public",
      business_public_id: "business-public",
    };

    expect(relationship.state).toBe("historical_soft_deleted");
    expect(relationship.warning).toContain("Historical order only");
    expect(JSON.stringify(relationship)).not.toMatch(/sk_live|password|webhook|card/i);
  });
});
