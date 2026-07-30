export type UserStatus =
  | "pending"
  | "active"
  | "suspended"
  | "locked"
  | "disabled";

export type RoleSlug =
  | "customer"
  | "restaurant_owner"
  | "restaurant_manager"
  | "restaurant_staff"
  | "super_admin";

export type RestaurantAssignment = {
  id: number;
  public_id?: string | null;
  name: string | null;
  slug: string | null;
  role: RoleSlug | string | null;
  status: string;
};

export type AuthUser = {
  id: number;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  phone: string | null;
  status: UserStatus;
  email_verified_at: string | null;
  last_login_at: string | null;
  roles: string[];
  permissions: string[];
  mfa_enabled: boolean;
  restaurants: RestaurantAssignment[];
  primary_restaurant_id: number | null;
};

export type AuthSession = {
  id: number;
  device_label: string | null;
  ip_address: string | null;
  last_activity_at: string | null;
  is_current: boolean;
  created_at: string | null;
};

export type AuthStatus = "loading" | "authenticated" | "guest";
