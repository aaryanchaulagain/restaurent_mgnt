import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { ForgotPasswordForm } from "@/features/auth/components/forgot-password-form";

export default function ForgotPasswordPage() {
  return (
    <AuthPageShell
      title="Forgot password"
      subtitle="Enter your email and we’ll send a Khana password reset link if an account exists."
      footer={
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Back to sign in
        </Link>
      }
    >
      <ForgotPasswordForm />
    </AuthPageShell>
  );
}
