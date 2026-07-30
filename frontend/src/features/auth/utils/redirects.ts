import type { AuthUser } from "../types";
import { isRestaurantUser, isSuperAdmin } from "./roles";

const SAFE_INTERNAL = /^\/(?!\/)/;

export function safeRedirectPath(
  candidate: string | null | undefined,
  fallback = "/",
): string {
  if (!candidate) return fallback;
  try {
    if (candidate.startsWith("http://") || candidate.startsWith("https://")) {
      const url = new URL(candidate);
      if (typeof window !== "undefined" && url.origin === window.location.origin) {
        return `${url.pathname}${url.search}${url.hash}` || fallback;
      }
      return fallback;
    }
  } catch {
    return fallback;
  }

  if (!SAFE_INTERNAL.test(candidate)) return fallback;
  if (candidate.startsWith("//")) return fallback;
  return candidate;
}

export function defaultRedirectForUser(user: AuthUser): string {
  if (isSuperAdmin(user)) return "/admin/dashboard";
  if (isRestaurantUser(user)) return "/restaurant/dashboard";
  return "/profile";
}

export function intendedFromSearch(search: string): string | null {
  const params = new URLSearchParams(search);
  return params.get("next") ?? params.get("redirect") ?? params.get("intended");
}
