import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function LockedAccountPage() {
  return (
    <AuthPageShell
      title="Account temporarily locked"
      subtitle="Too many unsuccessful sign-in attempts were detected."
      footer={
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Try again later
        </Link>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        For your security, wait about 15 minutes before signing in again, or reset your password.
      </p>
    </AuthPageShell>
  );
}
