import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { MfaChallengeForm } from "@/features/auth/components/mfa-challenge-form";

export default function MfaChallengePage() {
  return (
    <AuthPageShell
      title="Two-factor authentication"
      subtitle="Enter the code from your authenticator app to finish signing in."
      footer={
        <Link href="/mfa/recovery" className="font-semibold text-[var(--color-burnt-orange)]">
          Use a recovery code
        </Link>
      }
    >
      <MfaChallengeForm />
    </AuthPageShell>
  );
}
