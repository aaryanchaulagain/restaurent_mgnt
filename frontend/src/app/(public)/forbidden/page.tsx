import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function ForbiddenPage() {
  return (
    <AuthPageShell
      title="Permission denied"
      subtitle="You don’t have access to this area of Suvakamana."
      footer={
        <Link href="/" className="font-semibold text-[var(--color-burnt-orange)]">
          Return home
        </Link>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        If you believe this is a mistake, contact support or switch to an account with the right
        role.
      </p>
    </AuthPageShell>
  );
}
