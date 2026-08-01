import { Suspense } from "react";
import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { LoginForm } from "@/features/auth/components/login-form";
import { SuperAdminGuestGuard } from "@/features/auth/guards/route-guard";

export default function SuperAdminLoginPage() {
  return (
    <SuperAdminGuestGuard>
      <AuthPageShell
        title="Super admin"
        subtitle="Sign in with an authorized super administrator account."
        footer={
          <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
            Customer or partner login
          </Link>
        }
      >
        <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Loading…</p>}>
          <LoginForm portal="super_admin" />
        </Suspense>
      </AuthPageShell>
    </SuperAdminGuestGuard>
  );
}
