"use client";

import { AdminGuard } from "@/features/auth/guards/route-guard";

export default function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AdminGuard>{children}</AdminGuard>;
}
