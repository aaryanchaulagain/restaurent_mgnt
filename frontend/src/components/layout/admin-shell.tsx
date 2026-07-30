"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { cn } from "@/lib/utils";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { clearRestaurantContext } from "@/features/restaurant/lib/restaurant-context";
import { Button } from "@/components/ui/button";

function useAdminLogout() {
  const router = useRouter();
  const { logout } = useAuth();

  return async function handleLogout() {
    try {
      clearRestaurantContext();
      await logout();
    } finally {
      router.replace("/login");
    }
  };
}

export function AdminSidebar({
  brand,
  items,
  portalLabel,
}: {
  brand: string;
  portalLabel: string;
  items: { href: string; label: string }[];
}) {
  const pathname = usePathname();
  const { user } = useAuth();
  const handleLogout = useAdminLogout();

  return (
    <aside className="hidden w-64 shrink-0 flex-col bg-[var(--color-warm-black)] text-white lg:flex">
      <div className="border-b border-white/10 px-5 py-6">
        <p className="font-[family-name:var(--font-display)] text-2xl">{brand}</p>
        <p className="mt-1 text-xs tracking-wider text-[var(--color-warm-gold)] uppercase">
          {portalLabel}
        </p>
      </div>
      <nav className="flex-1 space-y-1 overflow-y-auto p-3" aria-label="Admin">
        {items.map((item) => {
          const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
          return (
            <Link
              key={item.href}
              href={item.href}
              className={cn(
                "block rounded-[var(--radius-md)] px-3 py-2.5 text-sm transition",
                active
                  ? "bg-[rgba(216,102,45,0.2)] text-[var(--color-warm-gold)]"
                  : "text-white/75 hover:bg-white/5 hover:text-white",
              )}
            >
              {item.label}
            </Link>
          );
        })}
      </nav>
      <div className="border-t border-white/10 p-4">
        {user ? (
          <p className="mb-3 truncate text-xs text-white/60" title={user.email}>
            {user.email}
          </p>
        ) : null}
        <Button
          size="sm"
          variant="outline"
          className="w-full border-white/20 bg-transparent text-white hover:bg-white/10"
          onClick={() => void handleLogout()}
        >
          Log out
        </Button>
      </div>
    </aside>
  );
}

export function AdminHeader({
  title,
  subtitle,
  actions,
}: {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
}) {
  const { user } = useAuth();
  const handleLogout = useAdminLogout();

  return (
    <div className="flex flex-col gap-4 border-b border-[var(--border-subtle)] bg-[var(--surface-elevated)] px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
      <div className="min-w-0">
        <h1 className="font-[family-name:var(--font-display)] text-3xl text-[var(--text-primary)]">
          {title}
        </h1>
        {subtitle ? (
          <p className="mt-1 text-sm text-[var(--text-secondary)]">{subtitle}</p>
        ) : null}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {actions}
        {user ? (
          <span className="hidden text-xs text-[var(--text-muted)] sm:inline max-w-[160px] truncate">
            {user.email}
          </span>
        ) : null}
        <Button size="sm" variant="outline" onClick={() => void handleLogout()}>
          Log out
        </Button>
      </div>
    </div>
  );
}

export function AdminShell({
  brand,
  portalLabel,
  items,
  title,
  subtitle,
  actions,
  children,
}: {
  brand: string;
  portalLabel: string;
  items: { href: string; label: string }[];
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const handleLogout = useAdminLogout();

  return (
    <div className="flex min-h-screen bg-[var(--surface-muted)]">
      <AdminSidebar brand={brand} portalLabel={portalLabel} items={items} />
      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center gap-2 border-b border-[var(--border-subtle)] bg-[var(--color-warm-black)] px-3 py-3 lg:hidden">
          <div className="flex flex-1 gap-2 overflow-x-auto">
            {items.map((item) => (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "shrink-0 rounded-full px-3 py-1.5 text-xs font-medium",
                  pathname.startsWith(item.href)
                    ? "bg-[var(--color-burnt-orange)] text-white"
                    : "bg-white/10 text-white/80",
                )}
              >
                {item.label}
              </Link>
            ))}
          </div>
          <button
            type="button"
            onClick={() => void handleLogout()}
            className="shrink-0 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white/90"
          >
            Log out
          </button>
        </div>
        <AdminHeader title={title} subtitle={subtitle} actions={actions} />
        <main className="flex-1 px-4 py-6 sm:px-6">{children}</main>
      </div>
    </div>
  );
}
