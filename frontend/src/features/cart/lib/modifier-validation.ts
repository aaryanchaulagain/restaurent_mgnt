export type ModifierGroupLike = {
  public_id: string;
  selection_type: "single" | "multiple";
  minimum_selections: number;
  maximum_selections: number;
  is_required: boolean;
  options: Array<{ public_id: string }>;
};

export function validateModifierSelection(
  group: ModifierGroupLike,
  selectedOptionIds: string[],
): string | null {
  const selected = group.options.filter((o) => selectedOptionIds.includes(o.public_id));
  const count = selected.length;

  if (group.is_required && count < Math.max(1, group.minimum_selections)) {
    return `Select at least ${Math.max(1, group.minimum_selections)} option(s) for required group.`;
  }
  if (count > group.maximum_selections) {
    return `At most ${group.maximum_selections} option(s) allowed.`;
  }
  if (group.selection_type === "single" && count > 1) {
    return "Only one option may be selected.";
  }
  if (count < group.minimum_selections) {
    return `Select at least ${group.minimum_selections} option(s).`;
  }
  return null;
}

export function allModifierGroupsValid(
  groups: ModifierGroupLike[],
  selectedOptionIds: string[],
): boolean {
  return groups.every((g) => validateModifierSelection(g, selectedOptionIds) === null);
}
