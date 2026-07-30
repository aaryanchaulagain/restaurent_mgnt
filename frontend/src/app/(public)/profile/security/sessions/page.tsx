import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { SessionsPanel } from "@/features/auth/components/sessions-panel";
import { AuthGuard } from "@/features/auth/guards/route-guard";

export default function SessionsPage() {
  return (
    <AuthGuard requireMfaForAdmin={false}>
      <AuthPageShell
        title="Active sessions"
        subtitle="Review devices signed in to your account and revoke any you don’t recognise."
        footer={
          <Link href="/profile/security" className="font-semibold text-[var(--color-burnt-orange)]">
            Back to security
          </Link>
        }
      >
        <SessionsPanel />
      </AuthPageShell>
    </AuthGuard>
  );
}
