import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/brand";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function ForbiddenPage() {
  return (
    <AuthPageShell
      title="Permission denied"
      subtitle={`You don’t have access to this area of ${PLATFORM_NAME}.`}
      footer={
        <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
          <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
            Go to login
          </Link>
          <span className="hidden text-[var(--text-muted)] sm:inline">·</span>
          <Link href="/" className="font-semibold text-[var(--color-burnt-orange)]">
            Return home
          </Link>
        </div>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        If you believe this is a mistake, contact support or switch to an account with the right
        role.
      </p>
    </AuthPageShell>
  );
}
