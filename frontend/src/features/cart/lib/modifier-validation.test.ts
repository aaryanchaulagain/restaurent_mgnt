import { describe, expect, it } from "vitest";
import { allModifierGroupsValid, validateModifierSelection } from "./modifier-validation";

describe("modifier validation", () => {
  const requiredGroup = {
    public_id: "g1",
    selection_type: "single" as const,
    minimum_selections: 1,
    maximum_selections: 1,
    is_required: true,
    options: [{ public_id: "o1" }, { public_id: "o2" }],
  };

  it("requires selection for required groups", () => {
    expect(validateModifierSelection(requiredGroup, [])).toMatch(/required/i);
  });

  it("rejects too many selections", () => {
    const multi = {
      ...requiredGroup,
      selection_type: "multiple" as const,
      maximum_selections: 1,
      minimum_selections: 0,
      is_required: false,
    };
    expect(validateModifierSelection(multi, ["o1", "o2"])).toMatch(/at most/i);
  });

  it("validates all groups", () => {
    expect(allModifierGroupsValid([requiredGroup], ["o1"])).toBe(true);
  });
});
