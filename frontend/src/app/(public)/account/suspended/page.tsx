import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";

export default function SuspendedAccountPage() {
  return (
    <AuthPageShell
      title="Account suspended"
      subtitle="Access to this account has been paused."
      footer={
        <Link href="/" className="font-semibold text-[var(--color-burnt-orange)]">
          Return home
        </Link>
      }
    >
      <p className="text-sm text-[var(--text-secondary)]">
        Please contact Suvakamana support if you need help restoring access.
      </p>
    </AuthPageShell>
  );
}
