"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { AuthGuard } from "@/features/auth/guards/route-guard";
import { useCurrentPartnerApplication } from "@/features/partner/hooks/use-partner-application";

function PartnerApplicationRedirect() {
  const router = useRouter();
  const { data, isLoading, isError } = useCurrentPartnerApplication();

  useEffect(() => {
    if (isLoading) return;
    if (isError || !data) {
      router.replace("/partner/apply");
      return;
    }
    router.replace(`/partner/applications/${data.public_id}`);
  }, [data, isLoading, isError, router]);

  return (
    <main className="mx-auto max-w-3xl px-4 py-16 text-center">
      <p className="text-sm text-[var(--text-secondary)]">Loading your application…</p>
    </main>
  );
}

export default function PartnerApplicationHubPage() {
  return (
    <AuthGuard requireMfaForAdmin={false}>
      <PartnerApplicationRedirect />
    </AuthGuard>
  );
}
