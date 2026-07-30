"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { Breadcrumbs } from "@/components/ui/navigation";
import { useCurrentUser } from "@/features/auth/hooks/use-auth";
import { CustomerGuard } from "@/features/auth/guards/route-guard";

export default function ProfilePage() {
  return (
    <CustomerGuard>
      <ProfileContent />
    </CustomerGuard>
  );
}

function ProfileContent() {
  const { user } = useCurrentUser();
  const fullName = user ? `${user.first_name} ${user.last_name}` : "";

  return (
    <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
      <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Profile" }]} />
      <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-4xl">Your profile</h1>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            Manage personal details and saved addresses.
          </p>
        </div>
        <div className="flex flex-wrap gap-2 text-sm font-semibold">
          <Link href="/profile/orders" className="text-[var(--color-burnt-orange)]">
            Orders
          </Link>
          <span className="text-[var(--text-muted)]">·</span>
          <Link href="/profile/favourites" className="text-[var(--color-burnt-orange)]">
            Favourites
          </Link>
          <span className="text-[var(--text-muted)]">·</span>
          <Link href="/profile/security" className="text-[var(--color-burnt-orange)]">
            Security
          </Link>
        </div>
      </div>

      <div className="mt-8 grid gap-6 lg:grid-cols-2">
        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
          <h2 className="text-2xl">Personal details</h2>
          <div className="mt-4 space-y-4">
            <Field label="Name" htmlFor="name">
              <Input id="name" defaultValue={fullName} key={fullName} readOnly />
            </Field>
            <Field label="Email" htmlFor="email">
              <Input id="email" defaultValue={user?.email ?? ""} key={user?.email ?? "email"} readOnly />
            </Field>
            <Field label="Phone" htmlFor="phone">
              <Input id="phone" defaultValue={user?.phone ?? ""} key={user?.phone ?? "phone"} readOnly />
            </Field>
            <Button type="button" variant="secondary" disabled>
              Save changes
            </Button>
          </div>
        </section>

        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
          <h2 className="text-2xl">Saved addresses</h2>
          <ul className="mt-4 space-y-3 text-sm">
            <li className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3">
              <p className="font-semibold">Home</p>
              <p className="text-[var(--text-secondary)]">14 Lazimpat Road, Kathmandu</p>
            </li>
            <li className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3">
              <p className="font-semibold">Office</p>
              <p className="text-[var(--text-secondary)]">88 Durbar Marg, Kathmandu</p>
            </li>
          </ul>
          <Button type="button" variant="outline" className="mt-4">
            Add address
          </Button>
        </section>
      </div>
    </main>
  );
}
