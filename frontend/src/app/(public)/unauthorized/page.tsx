import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function UnauthorizedPage() {
  return (
    <AuthPageShell
      title="Sign in required"
      subtitle="You need an account session to view this page."
      footer={
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Go to sign in
        </Link>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        Your session may have ended, or you opened a private link while signed out.
      </p>
    </AuthPageShell>
  );
}
