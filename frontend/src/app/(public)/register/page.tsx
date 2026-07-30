import Link from "next/link";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { RegisterForm } from "@/features/auth/components/register-form";
import { GuestGuard } from "@/features/auth/guards/route-guard";

export default function RegisterPage() {
  return (
    <GuestGuard>
      <AuthPageShell
        title="Create your account"
        subtitle="Save addresses, track deliveries and reorder in one place."
        footer={
          <>
            Already registered?{" "}
            <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
              Sign in
            </Link>
          </>
        }
      >
        <RegisterForm />
      </AuthPageShell>
    </GuestGuard>
  );
}
