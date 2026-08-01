/** Khana = marketplace platform. Partner businesses (restaurants, bakeries, etc.) sell on Khana. */
export const PLATFORM_NAME = "Khana";

export const PLATFORM_TAGLINE =
  "Restaurants, bakeries, butcheries and groceries — all in one place.";

export type VendorType = "restaurant" | "bakery" | "butchery" | "grocery";

export const VENDOR_TYPES: Array<{ value: VendorType; label: string }> = [
  { value: "restaurant", label: "Restaurant" },
  { value: "bakery", label: "Bakery" },
  { value: "butchery", label: "Butchery" },
  { value: "grocery", label: "Grocery" },
];

export function vendorTypeLabel(type: string | null | undefined): string {
  return VENDOR_TYPES.find((t) => t.value === type)?.label ?? "Restaurant";
}
