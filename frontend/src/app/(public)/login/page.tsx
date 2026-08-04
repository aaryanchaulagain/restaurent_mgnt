import { Suspense } from "react";
import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { LoginForm } from "@/features/auth/components/login-form";
import { GuestGuard } from "@/features/auth/guards/route-guard";

export default function LoginPage() {
  return (
    <GuestGuard>
      <AuthPageShell
        title="Welcome back"
        subtitle="Sign in to your Khana customer or partner account."
        footer={
          <>
            New here?{" "}
            <Link href="/register" className="font-semibold text-[var(--color-burnt-orange)]">
              Create an account
            </Link>
          </>
        }
      >
        <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Loading…</p>}>
          <LoginForm />
        </Suspense>
      </AuthPageShell>
    </GuestGuard>
  );
}
