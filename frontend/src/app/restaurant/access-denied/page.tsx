"use client";

import Link from "next/link";
import { AdminShell } from "@/components/layout/admin-shell";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { Button } from "@/components/ui/button";

export default function AccessDeniedPage() {
  const shell = useRestaurantShell();

  return (
    <AdminShell
      brand={shell.brand}
      portalLabel={shell.portalLabel}
      items={shell.items}
      title="Access denied"
      subtitle="You do not have permission for this module in the selected branch."
    >
      <div className="max-w-lg space-y-4 rounded-lg border bg-white p-6">
        <p className="text-sm text-[var(--text-secondary)]">
          Navigation hides modules you cannot use, but the server still authorizes every request.
          Switch branch if you manage more than one, or ask an owner to grant access.
        </p>
        <div className="flex flex-wrap gap-3">
          <Link href="/restaurant/dashboard">
            <Button type="button">Back to dashboard</Button>
          </Link>
          <Link href="/restaurant/branches">
            <Button type="button" variant="secondary">
              Branches
            </Button>
          </Link>
        </div>
      </div>
    </AdminShell>
  );
}
