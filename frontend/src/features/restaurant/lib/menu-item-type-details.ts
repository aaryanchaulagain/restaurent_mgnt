export type BakeryTypeDetails = {
  schema?: "bakery";
  flavour?: string | null;
  eggless?: boolean;
  minimum_notice_hours?: number | null;
  custom_message_allowed?: boolean;
  serves_people?: number | null;
};

export type GroceryTypeDetails = {
  schema?: "grocery";
  brand?: string | null;
  barcode?: string | null;
  manufacturer?: string | null;
  package_size?: string | null;
  max_purchase_quantity?: number | null;
};

export type ButcherFixedWeightVariant = {
  name: string;
  weight_grams: number;
};

export type ButcherTypeDetails = {
  schema?: "butcher";
  animal_type?: string | null;
  cut_type?: string | null;
  storage?: "fresh" | "frozen" | null;
  bone_in?: boolean | null;
  skin_on?: boolean | null;
  fixed_weight_grams?: number | null;
  fixed_weight_variants?: ButcherFixedWeightVariant[];
};

export type MenuItemTypeDetails =
  | BakeryTypeDetails
  | GroceryTypeDetails
  | ButcherTypeDetails
  | Record<string, unknown>;

export function emptyTypeDetailsFor(
  type: string,
): BakeryTypeDetails | GroceryTypeDetails | ButcherTypeDetails | null {
  switch (type) {
    case "bakery":
      return {
        schema: "bakery",
        flavour: "",
        eggless: false,
        minimum_notice_hours: null,
        custom_message_allowed: false,
        serves_people: null,
      };
    case "grocery":
      return {
        schema: "grocery",
        brand: "",
        barcode: "",
        manufacturer: "",
        package_size: "",
        max_purchase_quantity: null,
      };
    case "butcher":
      return {
        schema: "butcher",
        animal_type: "",
        cut_type: "",
        storage: null,
        bone_in: null,
        skin_on: null,
        fixed_weight_grams: null,
        fixed_weight_variants: [],
      };
    default:
      return null;
  }
}
