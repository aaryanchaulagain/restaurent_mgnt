import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { MfaSetupPanel } from "@/features/auth/components/mfa-setup-panel";
import { AuthGuard } from "@/features/auth/guards/route-guard";

export default function MfaSetupPage() {
  return (
    <AuthGuard roles={["super_admin"]} requireMfaForAdmin={false}>
      <AuthPageShell
        title="Set up MFA"
        subtitle="Authenticator apps are required for super-admin access."
      >
        <MfaSetupPanel />
      </AuthPageShell>
    </AuthGuard>
  );
}
