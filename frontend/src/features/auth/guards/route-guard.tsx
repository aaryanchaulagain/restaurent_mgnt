"use client";

import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { useAuth } from "../hooks/use-auth";
import type { RoleSlug } from "../types";
import {
  hasAnyRole,
  hasPermission,
  isAccountBlocked,
  isCustomer,
  isRestaurantUser,
  isSuperAdmin,
} from "../utils/roles";

function GuardShell({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-[50vh] items-center justify-center px-4" aria-busy="true">
      <p className="text-sm text-[var(--text-secondary)]">{children}</p>
    </div>
  );
}

type GuardProps = {
  children: ReactNode;
  roles?: Array<RoleSlug | string>;
  permissions?: string[];
  requireVerified?: boolean;
  requireMfaForAdmin?: boolean;
};

function mfaRequiredForAdmin(): boolean {
  return process.env.NEXT_PUBLIC_REQUIRE_SUPER_ADMIN_MFA !== "false";
}

export function AuthGuard({
  children,
  roles,
  permissions,
  requireVerified = true,
  requireMfaForAdmin = mfaRequiredForAdmin(),
}: GuardProps) {
  const { user, status, isLoading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (isLoading) return;

    if (status === "guest" || !user) {
      const next = encodeURIComponent(
        `${window.location.pathname}${window.location.search}`,
      );
      router.replace(`/login?next=${next}`);
      return;
    }

    if (user.status === "suspended" || user.status === "disabled") {
      router.replace("/account/suspended");
      return;
    }

    if (user.status === "locked") {
      router.replace("/account/locked");
      return;
    }

    if (requireVerified && !user.email_verified_at) {
      router.replace("/verify-email");
      return;
    }

    if (roles && !hasAnyRole(user, roles)) {
      // Partners hitting customer-only pages (e.g. old /profile links) go home to their portal,
      // not a dead-end forbidden screen.
      if (isRestaurantUser(user) && roles.includes("customer")) {
        router.replace("/restaurant/dashboard");
        return;
      }
      if (isSuperAdmin(user)) {
        router.replace("/admin/dashboard");
        return;
      }
      router.replace("/forbidden");
      return;
    }

    if (permissions && !permissions.every((p) => hasPermission(user, p))) {
      router.replace("/forbidden");
      return;
    }

    if (requireMfaForAdmin && isSuperAdmin(user) && !user.mfa_enabled) {
      router.replace("/mfa/setup");
      return;
    }
  }, [
    isLoading,
    status,
    user,
    roles,
    permissions,
    requireVerified,
    requireMfaForAdmin,
    router,
  ]);

  if (isLoading || status === "loading") {
    return <GuardShell>Checking your session…</GuardShell>;
  }

  if (!user || isAccountBlocked(user)) {
    return <GuardShell>Redirecting…</GuardShell>;
  }

  if (requireVerified && !user.email_verified_at) {
    return <GuardShell>Email verification required…</GuardShell>;
  }

  if (roles && !hasAnyRole(user, roles)) {
    return <GuardShell>Redirecting…</GuardShell>;
  }

  if (permissions && !permissions.every((p) => hasPermission(user, p))) {
    return <GuardShell>Redirecting…</GuardShell>;
  }

  if (requireMfaForAdmin && isSuperAdmin(user) && !user.mfa_enabled) {
    return <GuardShell>MFA setup required…</GuardShell>;
  }

  return <>{children}</>;
}

export function GuestGuard({ children }: { children: ReactNode }) {
  const { user, status, isLoading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (isLoading || status !== "authenticated" || !user) return;
    // Every portal landing page requires a verified email, so routing an
    // unverified account there only bounces it on to /verify-email.
    if (!user.email_verified_at) {
      router.replace("/verify-email");
    } else if (isSuperAdmin(user)) {
      router.replace("/admin/dashboard");
    } else if (isRestaurantUser(user)) {
      router.replace("/restaurant/dashboard");
    } else if (isCustomer(user)) {
      router.replace("/profile");
    } else {
      // No role area to land on; sending them to a guarded page would bounce
      // back here through /forbidden and trap them in a redirect loop.
      router.replace("/");
    }
  }, [isLoading, status, user, router]);

  if (isLoading) {
    return <GuardShell>Loading…</GuardShell>;
  }

  if (status === "authenticated" && user) {
    return <GuardShell>Redirecting…</GuardShell>;
  }

  return <>{children}</>;
}

/**
 * Guest guard for /superadmin/login. The page stays publicly reachable for guests,
 * customers and restaurant users; only a signed-in super admin is sent to the dashboard.
 */
export function SuperAdminGuestGuard({ children }: { children: ReactNode }) {
  const { user, status, isLoading } = useAuth();
  const router = useRouter();
  const alreadySuperAdmin = status === "authenticated" && Boolean(user) && isSuperAdmin(user);

  useEffect(() => {
    if (isLoading || !alreadySuperAdmin) return;
    router.replace("/admin/dashboard");
  }, [isLoading, alreadySuperAdmin, router]);

  if (isLoading) {
    return <GuardShell>Loading…</GuardShell>;
  }

  if (alreadySuperAdmin) {
    return <GuardShell>Redirecting…</GuardShell>;
  }

  return <>{children}</>;
}

export function CustomerGuard({ children }: { children: ReactNode }) {
  return (
    <AuthGuard roles={["customer"]} requireMfaForAdmin={false}>
      {children}
    </AuthGuard>
  );
}

export function RestaurantGuard({ children }: { children: ReactNode }) {
  const { user, isLoading, status } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (isLoading || status === "loading" || !user) return;
    if (isSuperAdmin(user)) {
      const contextId =
        typeof window !== "undefined"
          ? window.localStorage.getItem("suvakamana_restaurant_context_public_id")
          : null;
      if (!contextId) {
        router.replace("/admin/restaurants");
      }
    }
  }, [user, isLoading, status, router]);

  if (!isLoading && user && isSuperAdmin(user)) {
    const contextId =
      typeof window !== "undefined"
        ? window.localStorage.getItem("suvakamana_restaurant_context_public_id")
        : null;
    if (!contextId) {
      return <GuardShell>Select a restaurant from admin…</GuardShell>;
    }
    return (
      <AuthGuard roles={["super_admin"]} requireMfaForAdmin={mfaRequiredForAdmin()}>
        {children}
      </AuthGuard>
    );
  }

  return (
    <AuthGuard
      roles={[
        "restaurant_owner",
        "restaurant_manager",
        "restaurant_staff",
        "business_owner",
        "business_admin",
        "branch_manager",
        "order_manager",
        "kitchen_staff",
        "inventory_manager",
        "delivery_staff",
      ]}
      permissions={["view_restaurant_dashboard"]}
      requireMfaForAdmin={false}
    >
      {children}
    </AuthGuard>
  );
}

export function AdminGuard({ children }: { children: ReactNode }) {
  return (
    <AuthGuard
      roles={["super_admin"]}
      permissions={["view_super_admin_dashboard"]}
      requireMfaForAdmin={mfaRequiredForAdmin()}
    >
      {children}
    </AuthGuard>
  );
}
