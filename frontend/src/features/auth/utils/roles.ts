import type { AuthUser, RoleSlug } from "../types";

const RESTAURANT_ROLES: RoleSlug[] = [
  "restaurant_owner",
  "restaurant_manager",
  "restaurant_staff",
];

export function hasRole(user: AuthUser | null | undefined, role: RoleSlug | string): boolean {
  return Boolean(user?.roles?.includes(role));
}

export function hasAnyRole(
  user: AuthUser | null | undefined,
  roles: Array<RoleSlug | string>,
): boolean {
  return roles.some((role) => hasRole(user, role));
}

export function hasPermission(
  user: AuthUser | null | undefined,
  permission: string,
): boolean {
  return Boolean(user?.permissions?.includes(permission));
}

export function isCustomer(user: AuthUser | null | undefined): boolean {
  return hasRole(user, "customer");
}

export function isRestaurantUser(user: AuthUser | null | undefined): boolean {
  return hasAnyRole(user, RESTAURANT_ROLES);
}

export function isSuperAdmin(user: AuthUser | null | undefined): boolean {
  return hasRole(user, "super_admin");
}

export function isAccountBlocked(user: AuthUser | null | undefined): boolean {
  if (!user) return false;
  return ["suspended", "disabled", "locked"].includes(user.status);
}

export function passwordStrength(password: string): {
  score: number;
  label: string;
} {
  let score = 0;
  if (password.length >= 8) score += 1;
  if (password.length >= 12) score += 1;
  if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score += 1;
  if (/[0-9]/.test(password)) score += 1;
  if (/[^A-Za-z0-9]/.test(password)) score += 1;

  const labels = ["Very weak", "Weak", "Fair", "Good", "Strong", "Excellent"];
  return { score, label: labels[Math.min(score, labels.length - 1)] };
}
