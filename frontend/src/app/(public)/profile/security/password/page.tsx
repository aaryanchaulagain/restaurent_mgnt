import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { ChangePasswordForm } from "@/features/auth/components/change-password-form";
import { AuthGuard } from "@/features/auth/guards/route-guard";

export default function ChangePasswordPage() {
  return (
    <AuthGuard requireMfaForAdmin={false}>
      <AuthPageShell
        title="Change password"
        subtitle="Choose a strong password you haven’t used elsewhere."
        footer={
          <Link href="/profile/security" className="font-semibold text-[var(--color-burnt-orange)]">
            Back to security
          </Link>
        }
      >
        <ChangePasswordForm />
      </AuthPageShell>
    </AuthGuard>
  );
}
