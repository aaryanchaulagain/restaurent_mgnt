"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { AuthGuard } from "@/features/auth/guards/route-guard";
import { useLogout } from "@/features/auth/hooks/use-auth";

export default function SecurityPage() {
  const { logout } = useLogout();

  return (
    <AuthGuard requireMfaForAdmin={false}>
      <main className="mx-auto max-w-lg px-4 py-10 sm:px-6">
        <p className="font-[family-name:var(--font-display)] text-3xl">Suvakamana</p>
        <h1 className="mt-4 text-4xl">Security</h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          Manage password and signed-in devices.
        </p>
        <div className="mt-8 space-y-3">
          <Link
            href="/profile/security/password"
            className="block rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-white p-4 text-sm font-semibold shadow-[var(--shadow-sm)]"
          >
            Change password
          </Link>
          <Link
            href="/profile/security/sessions"
            className="block rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-white p-4 text-sm font-semibold shadow-[var(--shadow-sm)]"
          >
            Active sessions
          </Link>
          <Button
            type="button"
            variant="outline"
            className="w-full"
            onClick={() => void logout().then(() => (window.location.href = "/login"))}
          >
            Sign out
          </Button>
        </div>
      </main>
    </AuthGuard>
  );
}
