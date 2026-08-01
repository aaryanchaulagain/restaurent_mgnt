/**
 * Frontend presentation config for marketplace verticals.
 * Authorization always comes from the backend; this file only drives labels and form visibility.
 */

export type BusinessVerticalType =
  | "restaurant"
  | "bakery"
  | "grocery"
  | "butcher"
  | "pharmacy"
  | "other";

export type BusinessTypeConfig = {
  type: BusinessVerticalType;
  label: string;
  portalLabel: string;
  catalogueLabel: string;
  categoryLabel: string;
  productLabel: string;
  productPluralLabel: string;
  addProductLabel: string;
  emptyCatalogueTitle: string;
  emptyCatalogueDescription: string;
  searchPlaceholder: string;
  savedCreateDescription: string;
  supportsVariants: boolean;
  supportsModifiers: boolean;
  supportsDietary: boolean;
  supportsPreparationTime: boolean;
  supportsCuisine: boolean;
  /** Phase 2: show additive type_details form fields. */
  supportsTypeDetails: boolean;
  defaultCategories: string[];
};

const CONFIG: Record<BusinessVerticalType, BusinessTypeConfig> = {
  restaurant: {
    type: "restaurant",
    label: "Restaurant",
    portalLabel: "Restaurant Admin",
    catalogueLabel: "Menu",
    categoryLabel: "Menu category",
    productLabel: "Menu item",
    productPluralLabel: "Menu items",
    addProductLabel: "Add menu item",
    emptyCatalogueTitle: "No menu items",
    emptyCatalogueDescription: "Create your first menu item to get started.",
    searchPlaceholder: "Search menu items",
    savedCreateDescription: "Your dish was saved.",
    supportsVariants: true,
    supportsModifiers: true,
    supportsDietary: true,
    supportsPreparationTime: true,
    supportsCuisine: true,
    supportsTypeDetails: false,
    defaultCategories: ["Starters", "Mains", "Drinks", "Desserts"],
  },
  bakery: {
    type: "bakery",
    label: "Bakery",
    portalLabel: "Bakery Admin",
    catalogueLabel: "Products",
    categoryLabel: "Category",
    productLabel: "Product",
    productPluralLabel: "Products",
    addProductLabel: "Add product",
    emptyCatalogueTitle: "No products",
    emptyCatalogueDescription: "Add your first bakery product to get started.",
    searchPlaceholder: "Search products",
    savedCreateDescription: "Your product was saved.",
    supportsVariants: true,
    supportsModifiers: true,
    supportsDietary: true,
    supportsPreparationTime: true,
    supportsCuisine: false,
    supportsTypeDetails: true,
    defaultCategories: ["Breads", "Pastries", "Cakes", "Savouries"],
  },
  grocery: {
    type: "grocery",
    label: "Grocery",
    portalLabel: "Grocery Admin",
    catalogueLabel: "Products",
    categoryLabel: "Category",
    productLabel: "Product",
    productPluralLabel: "Products",
    addProductLabel: "Add product",
    emptyCatalogueTitle: "No products",
    emptyCatalogueDescription: "Add your first grocery product to get started.",
    searchPlaceholder: "Search products",
    savedCreateDescription: "Your product was saved.",
    supportsVariants: true,
    supportsModifiers: false,
    supportsDietary: false,
    supportsPreparationTime: false,
    supportsCuisine: false,
    supportsTypeDetails: true,
    defaultCategories: ["Fresh", "Pantry", "Dairy", "Household"],
  },
  butcher: {
    type: "butcher",
    label: "Butchery",
    portalLabel: "Butchery Admin",
    catalogueLabel: "Products",
    categoryLabel: "Category",
    productLabel: "Cut",
    productPluralLabel: "Cuts",
    addProductLabel: "Add cut",
    emptyCatalogueTitle: "No cuts listed",
    emptyCatalogueDescription: "Add your first cut to get started.",
    searchPlaceholder: "Search cuts",
    savedCreateDescription: "Your cut was saved.",
    supportsVariants: true,
    supportsModifiers: true,
    supportsDietary: false,
    supportsPreparationTime: false,
    supportsCuisine: false,
    supportsTypeDetails: true,
    defaultCategories: ["Beef", "Chicken", "Lamb", "Pork"],
  },
  pharmacy: {
    type: "pharmacy",
    label: "Pharmacy",
    portalLabel: "Pharmacy Admin",
    catalogueLabel: "Products",
    categoryLabel: "Category",
    productLabel: "Product",
    productPluralLabel: "Products",
    addProductLabel: "Add product",
    emptyCatalogueTitle: "No products",
    emptyCatalogueDescription: "Add your first pharmacy product to get started.",
    searchPlaceholder: "Search products",
    savedCreateDescription: "Your product was saved.",
    supportsVariants: true,
    supportsModifiers: false,
    supportsDietary: false,
    supportsPreparationTime: false,
    supportsCuisine: false,
    supportsTypeDetails: false,
    defaultCategories: ["OTC", "Personal care", "Wellness"],
  },
  other: {
    type: "other",
    label: "Business",
    portalLabel: "Business Admin",
    catalogueLabel: "Products",
    categoryLabel: "Category",
    productLabel: "Product",
    productPluralLabel: "Products",
    addProductLabel: "Add product",
    emptyCatalogueTitle: "No products",
    emptyCatalogueDescription: "Add your first product to get started.",
    searchPlaceholder: "Search products",
    savedCreateDescription: "Your product was saved.",
    supportsVariants: true,
    supportsModifiers: true,
    supportsDietary: false,
    supportsPreparationTime: false,
    supportsCuisine: false,
    supportsTypeDetails: false,
    defaultCategories: ["General"],
  },
};

/** Normalize aliases from API / legacy vendor_type without rewriting stored data. */
export function normalizeBusinessType(
  type: string | null | undefined,
): BusinessVerticalType {
  if (!type || !type.trim()) return "other";
  const key = type.trim().toLowerCase();
  switch (key) {
    case "restaurant":
      return "restaurant";
    case "bakery":
      return "bakery";
    case "grocery":
    case "grocery_store":
      return "grocery";
    case "butcher":
    case "butchery":
    case "meat_shop":
      return "butcher";
    case "pharmacy":
      return "pharmacy";
    case "other":
      return "other";
    default:
      return "other";
  }
}

export function getBusinessTypeConfig(
  type: string | null | undefined,
): BusinessTypeConfig {
  return CONFIG[normalizeBusinessType(type)];
}

export const BUSINESS_VERTICAL_OPTIONS: Array<{
  value: BusinessVerticalType;
  label: string;
}> = (Object.keys(CONFIG) as BusinessVerticalType[]).map((value) => ({
  value,
  label: CONFIG[value].label,
}));
