/** Khana = marketplace platform. Partner businesses (restaurants, bakeries, etc.) sell on Khana. */
export const PLATFORM_NAME = "Khana";

export const PLATFORM_TAGLINE =
  "Restaurants, bakeries, butcheries and groceries — all in one place.";

/**
 * Legacy vendor_type values used by restaurants.vendor_type / admin provisioning.
 * Catalogue presentation should prefer businesses.business_type via
 * `normalizeBusinessType` / `getBusinessTypeConfig`.
 */
export type VendorType = "restaurant" | "bakery" | "butchery" | "grocery";

export const VENDOR_TYPES: Array<{ value: VendorType; label: string }> = [
  { value: "restaurant", label: "Restaurant" },
  { value: "bakery", label: "Bakery" },
  { value: "butchery", label: "Butchery" },
  { value: "grocery", label: "Grocery" },
];

export function vendorTypeLabel(type: string | null | undefined): string {
  if (!type) return "Restaurant";
  const legacy = VENDOR_TYPES.find((t) => t.value === type);
  if (legacy) return legacy.label;
  // Fall through to business-type labels (butcher, pharmacy, other).
  // Lazy require avoided — inline map keeps brand.ts dependency-light.
  switch (type) {
    case "butcher":
      return "Butchery";
    case "pharmacy":
      return "Pharmacy";
    case "other":
      return "Other";
    default:
      return "Restaurant";
  }
}
