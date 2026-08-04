"use client";

import { AuthGuard } from "@/features/auth/guards/route-guard";
import { Breadcrumbs } from "@/components/ui/navigation";
import { PartnerApplyWizard } from "@/features/partner/components/PartnerApplyWizard";

export default function PartnerApplyPage() {
  return (
    <AuthGuard requireMfaForAdmin={false}>
      <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <Breadcrumbs
          items={[
            { label: "Home", href: "/" },
            { label: "Partner application" },
          ]}
        />
        <h1 className="mt-4 text-4xl">Become a restaurant partner</h1>
        <p className="mt-2 text-[var(--text-secondary)]">
          Share your kitchen with customers who value craft. Applications are reviewed by the
          Khana team within two business days.
        </p>
        <PartnerApplyWizard />
      </main>
    </AuthGuard>
  );
}
