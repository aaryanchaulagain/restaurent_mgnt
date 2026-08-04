"use client";

import Link from "next/link";
import dynamic from "next/dynamic";
import { usePathname } from "next/navigation";
import { Menu, ShoppingBag, X } from "lucide-react";
import { useMemo, useState } from "react";
import { Button, buttonVariants } from "@/components/ui/button";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { isRestaurantUser, isSuperAdmin } from "@/features/auth/utils/roles";
import { useCart } from "@/features/cart/components/cart-provider";
import { cn } from "@/lib/utils";

// Only pulled in once the shopper actually opens the cart.
const CartDrawer = dynamic(
  () => import("@/components/layout/cart-drawer").then((m) => m.CartDrawer),
  { ssr: false },
);

/** Navbar CTA: Login for guests; never "Account". Super admin uses /superadmin/login. */
function navAuthHref(user: ReturnType<typeof useAuth>["user"]): string {
  if (!user) return "/login";
  if (isSuperAdmin(user)) return "/admin/dashboard";
  if (isRestaurantUser(user)) return "/restaurant/dashboard";
  return "/profile";
}

function navAuthLabel(user: ReturnType<typeof useAuth>["user"]): string {
  if (!user) return "Login";
  if (isSuperAdmin(user)) return "Admin Dashboard";
  if (isRestaurantUser(user)) return "Dashboard";
  return "Profile";
}

export function SiteHeader() {
  const pathname = usePathname();
  const { user, isAuthenticated, isLoading } = useAuth();
  const [open, setOpen] = useState(false);
  const [cartOpen, setCartOpen] = useState(false);
  const { itemCount, cart } = useCart();
  const cartHint = cart?.branch?.name ?? cart?.restaurant?.trading_name ?? null;

  const links = useMemo(
    () => [
      { href: "/restaurants", label: "Restaurants" },
      { href: "/partner/apply", label: "Partner with us" },
      { href: "/contact", label: "Contact" },
    ],
    [],
  );

  const authHref = isAuthenticated ? navAuthHref(user) : "/login";
  const authLabel = isAuthenticated ? navAuthLabel(user) : "Login";

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-[var(--border-subtle)] bg-[var(--surface-elevated)]/95 supports-[backdrop-filter]:bg-[var(--surface-elevated)]/85 supports-[backdrop-filter]:backdrop-blur-sm">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
          <Link
            href="/"
            className="font-[family-name:var(--font-display)] text-2xl tracking-tight text-[var(--text-primary)]"
          >
            Khana
          </Link>

          <nav className="hidden items-center gap-6 md:flex" aria-label="Primary">
            {links.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className={cn(
                  "text-sm font-medium transition",
                  pathname.startsWith(link.href)
                    ? "text-[var(--color-burnt-orange)]"
                    : "text-[var(--text-secondary)] hover:text-[var(--text-primary)]",
                )}
              >
                {link.label}
              </Link>
            ))}

            {!isLoading ? (
              <Link
                href={authHref}
                className={cn(buttonVariants({ size: "sm" }), "min-w-24")}
              >
                {authLabel}
              </Link>
            ) : null}

            <Button
              variant="outline"
              size="sm"
              onClick={() => setCartOpen(true)}
              aria-label="Open cart"
            >
              <ShoppingBag className="h-4 w-4" />
              Cart{itemCount > 0 ? ` (${itemCount})` : ""}
              {cartHint && itemCount > 0 ? (
                <span className="ml-1 hidden max-w-[8rem] truncate text-xs font-normal text-[var(--text-muted)] lg:inline">
                  · {cartHint}
                </span>
              ) : null}
            </Button>
          </nav>

          <div className="flex items-center gap-2 md:hidden">
            {!isLoading ? (
              <Link href={authHref} className={cn(buttonVariants({ size: "sm" }))}>
                {authLabel}
              </Link>
            ) : null}
            <Button
              variant="ghost"
              size="icon"
              onClick={() => setCartOpen(true)}
              aria-label="Open cart"
            >
              <ShoppingBag className="h-5 w-5" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => setOpen((v) => !v)}
              aria-label={open ? "Close menu" : "Open menu"}
            >
              {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </Button>
          </div>
        </div>

        {open ? (
          <nav
            className="border-t border-[var(--border-subtle)] bg-[var(--surface-elevated)] px-4 py-4 md:hidden"
            aria-label="Mobile"
          >
            <div className="flex flex-col gap-3">
              {links.map((link) => (
                <Link
                  key={link.href}
                  href={link.href}
                  className="text-sm font-medium text-[var(--text-primary)]"
                  onClick={() => setOpen(false)}
                >
                  {link.label}
                </Link>
              ))}
              {!isLoading ? (
                <Link
                  href={authHref}
                  className="text-sm font-semibold text-[var(--color-burnt-orange)]"
                  onClick={() => setOpen(false)}
                >
                  {authLabel}
                </Link>
              ) : null}
            </div>
          </nav>
        ) : null}
      </header>
      {cartOpen ? <CartDrawer open onClose={() => setCartOpen(false)} /> : null}
    </>
  );
}

export function MobileBottomNav() {
  const pathname = usePathname();
  const { user, isAuthenticated } = useAuth();

  const authHref = isAuthenticated ? navAuthHref(user) : "/login";
  const authLabel = isAuthenticated ? navAuthLabel(user) : "Login";

  const items = [
    { href: "/", label: "Home" },
    { href: "/restaurants", label: "Browse" },
    { href: "/cart", label: "Cart" },
    { href: authHref, label: authLabel },
  ];

  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--border-subtle)] bg-[var(--surface-elevated)]/95 supports-[backdrop-filter]:bg-[var(--surface-elevated)]/85 supports-[backdrop-filter]:backdrop-blur-sm md:hidden"
      aria-label="Bottom"
    >
      <ul className="mx-auto grid max-w-lg grid-cols-4">
        {items.map((item) => {
          const active =
            item.href === "/"
              ? pathname === "/"
              : item.href === "/login"
                ? pathname.startsWith("/login")
                : pathname.startsWith(item.href);
          return (
            <li key={`${item.href}-${item.label}`}>
              <Link
                href={item.href}
                className={cn(
                  "flex h-14 items-center justify-center text-xs font-semibold",
                  active
                    ? "text-[var(--color-burnt-orange)]"
                    : "text-[var(--text-muted)]",
                )}
              >
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
