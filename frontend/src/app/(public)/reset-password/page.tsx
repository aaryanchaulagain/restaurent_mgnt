import { Suspense } from "react";
import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { ResetPasswordForm } from "@/features/auth/components/reset-password-form";

export default function ResetPasswordPage() {
  return (
    <AuthPageShell
      title="Reset password"
      subtitle="Choose a new password for your Khana account."
      footer={
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Back to sign in
        </Link>
      }
    >
      <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Loading…</p>}>
        <ResetPasswordForm />
      </Suspense>
    </AuthPageShell>
  );
}
