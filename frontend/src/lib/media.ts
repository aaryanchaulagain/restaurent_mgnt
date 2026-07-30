/** Shared marketplace image fallbacks (must exist as remote or local public assets). */
export const MARKETPLACE_PLACEHOLDER_IMAGE =
  "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1200&q=80";

export const FOOD_PLACEHOLDER_IMAGE =
  "https://images.unsplash.com/photo-1546833999-b9f581a1996d?auto=format&fit=crop&w=800&q=80";

/**
 * Resolve API image URLs for Next.js Image.
 * Relative /storage paths are served by Laravel, not Next.
 */
export function resolveMediaUrl(
  url: string | null | undefined,
  fallback: string = MARKETPLACE_PLACEHOLDER_IMAGE,
): string {
  if (!url) return fallback;
  if (url.startsWith("http://") || url.startsWith("https://") || url.startsWith("data:")) {
    return url;
  }
  if (url.startsWith("/storage/") || url.startsWith("storage/")) {
    const apiOrigin =
      process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, "") ?? "http://localhost:8000";
    return `${apiOrigin}${url.startsWith("/") ? url : `/${url}`}`;
  }
  if (url.startsWith("/images/")) {
    // Legacy missing local placeholders — use remote fallback.
    return fallback;
  }
  return url;
}

export function pickCardImage(
  urls?: { card_url?: string; original_url?: string; thumbnail_url?: string } | null,
  fallback: string = FOOD_PLACEHOLDER_IMAGE,
): string {
  return resolveMediaUrl(urls?.card_url || urls?.original_url || urls?.thumbnail_url, fallback);
}
