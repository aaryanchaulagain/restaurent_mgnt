import { setRestaurantContextPublicId } from "@/features/restaurant/lib/restaurant-context";

/** Persist restaurant context and open the menu editor (works for super admin). */
export function openRestaurantMenuEditor(
  restaurantPublicId: string,
  path = "/restaurant/menu",
): void {
  setRestaurantContextPublicId(restaurantPublicId);
  window.location.href = path;
}
