import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { VerifyEmailPageContent } from "@/features/auth/components/verify-email";

export default function VerifyEmailPage() {
  return (
    <AuthPageShell
      title="Verify your email"
      subtitle="Confirm your address to activate your Khana account."
    >
      <VerifyEmailPageContent />
    </AuthPageShell>
  );
}
