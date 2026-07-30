import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function SessionExpiredPage() {
  return (
    <AuthPageShell
      title="Session expired"
      subtitle="Please sign in again to continue."
      footer={
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Sign in
        </Link>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        Your session ended due to inactivity or a security refresh. No account data was lost.
      </p>
    </AuthPageShell>
  );
}
