const STORAGE_KEY = "suvakamana_restaurant_context_public_id";

export function getRestaurantContextPublicId(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(STORAGE_KEY);
}

export function setRestaurantContextPublicId(publicId: string | null): void {
  if (typeof window === "undefined") return;
  if (!publicId) {
    window.localStorage.removeItem(STORAGE_KEY);
    return;
  }
  window.localStorage.setItem(STORAGE_KEY, publicId);
}

export function clearRestaurantContext(): void {
  setRestaurantContextPublicId(null);
}
