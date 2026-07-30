"use client";

import { AuthGuard } from "@/features/auth/guards/route-guard";

export default function ProfileSectionLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AuthGuard requireMfaForAdmin={false}>{children}</AuthGuard>;
}
