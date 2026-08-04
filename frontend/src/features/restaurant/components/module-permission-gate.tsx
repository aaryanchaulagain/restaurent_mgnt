"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { requiredPermissionsForPath } from "@/lib/admin-nav";
import {
  hasEffectivePermission,
  useBranchAuthorization,
} from "@/features/restaurant/hooks/use-branch-authorization";
import { getBranchContextPublicId } from "@/features/business/lib/branch-context";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { isSuperAdmin } from "@/features/auth/utils/roles";

/**
 * Soft UX gate for module routes. Backend EnsurePermission remains authoritative.
 */
export function ModulePermissionGate({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useAuth();
  const authz = useBranchAuthorization();
  const required = requiredPermissionsForPath(pathname);
  const branchId = getBranchContextPublicId();

  useEffect(() => {
    if (pathname.startsWith("/restaurant/access-denied")) return;
    if (!required || required.length === 0) return;
    if (!branchId) return;
    if (authz.isLoading || authz.isFetching) return;
    if (isSuperAdmin(user)) return;
    if (!authz.data) return;

    if (!hasEffectivePermission(authz.data.permissions, required)) {
      router.replace("/restaurant/access-denied");
    }
  }, [pathname, required, branchId, authz.isLoading, authz.isFetching, authz.data, user, router]);

  if (pathname.startsWith("/restaurant/access-denied")) {
    return <>{children}</>;
  }
  if (required && required.length > 0 && branchId && !isSuperAdmin(user)) {
    if (authz.isLoading) {
      return (
        <div className="flex min-h-[40vh] items-center justify-center px-4">
          <p className="text-sm text-[var(--text-secondary)]">Checking permissions…</p>
        </div>
      );
    }
    if (authz.data && !hasEffectivePermission(authz.data.permissions, required)) {
      return (
        <div className="flex min-h-[40vh] items-center justify-center px-4">
          <p className="text-sm text-[var(--text-secondary)]">Access denied…</p>
        </div>
      );
    }
  }

  return <>{children}</>;
}
