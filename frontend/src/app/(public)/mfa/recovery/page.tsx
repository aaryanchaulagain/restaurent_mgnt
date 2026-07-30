import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { MfaChallengeForm } from "@/features/auth/components/mfa-challenge-form";

export default function MfaRecoveryPage() {
  return (
    <AuthPageShell
      title="Recovery code"
      subtitle="Enter a one-time recovery code to access your account."
      footer={
        <Link href="/mfa/challenge" className="font-semibold text-[var(--color-burnt-orange)]">
          Use authenticator code
        </Link>
      }
    >
      <MfaChallengeForm recovery />
    </AuthPageShell>
  );
}
