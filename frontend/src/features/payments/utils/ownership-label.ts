export function ownershipLabel(type?: string | null): string {
  if (type === "first_party") return "Khana-operated";
  if (type === "third_party") return "Partner restaurant";
  return "Partner restaurant";
}

export function revenueOwnershipWording(type?: string | null): string {
  if (type === "first_party") return "Platform-owned revenue";
  return "Estimated restaurant share";
}
