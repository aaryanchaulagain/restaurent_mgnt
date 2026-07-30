"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Menu, ShoppingBag, X } from "lucide-react";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { CartDrawer } from "@/components/layout/cart-drawer";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { useCart } from "@/features/cart/components/cart-provider";
import { cn } from "@/lib/utils";

export function SiteHeader() {
  const pathname = usePathname();
  const { isAuthenticated } = useAuth();
  const [open, setOpen] = useState(false);
  const [cartOpen, setCartOpen] = useState(false);
  const { itemCount } = useCart();

  const links = useMemo(
    () => [
      { href: "/restaurants", label: "Restaurants" },
      { href: "/partner/apply", label: "Partner with us" },
      { href: "/contact", label: "Contact" },
      isAuthenticated
        ? { href: "/profile", label: "Account" }
        : { href: "/login", label: "Sign in" },
    ],
    [isAuthenticated],
  );

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-[var(--border-subtle)] bg-[var(--surface-glass)] backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
          <Link
            href="/"
            className="font-[family-name:var(--font-display)] text-2xl tracking-tight text-[var(--text-primary)]"
          >
            Suvakamana
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
            <Button
              variant="outline"
              size="sm"
              onClick={() => setCartOpen(true)}
              aria-label="Open cart"
            >
              <ShoppingBag className="h-4 w-4" />
              Cart{itemCount > 0 ? ` (${itemCount})` : ""}
            </Button>
          </nav>

          <div className="flex items-center gap-2 md:hidden">
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
            </div>
          </nav>
        ) : null}
      </header>
      <CartDrawer open={cartOpen} onClose={() => setCartOpen(false)} />
    </>
  );
}

export function SiteFooter() {
  return (
    <footer className="mt-auto border-t border-[var(--border-subtle)] bg-[var(--color-warm-black)] text-[var(--text-inverse)]">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-4">
        <div className="md:col-span-2">
          <p className="font-[family-name:var(--font-display)] text-3xl">Suvakamana</p>
          <p className="mt-3 max-w-md text-sm leading-relaxed text-white/70">
            A premium marketplace connecting independent restaurants with customers who care
            about flavour, craft and reliability.
          </p>
        </div>
        <div>
          <p className="text-xs font-semibold tracking-wider text-[var(--color-warm-gold)] uppercase">
            Explore
          </p>
          <ul className="mt-3 space-y-2 text-sm text-white/75">
            <li>
              <Link href="/restaurants">Restaurants</Link>
            </li>
            <li>
              <Link href="/partner/apply">Become a partner</Link>
            </li>
            <li>
              <Link href="/contact">Contact</Link>
            </li>
            <li>
              <Link href="/orders/track">Track an order</Link>
            </li>
          </ul>
        </div>
        <div>
          <p className="text-xs font-semibold tracking-wider text-[var(--color-warm-gold)] uppercase">
            Account
          </p>
          <ul className="mt-3 space-y-2 text-sm text-white/75">
            <li>
              <Link href="/login">Sign in</Link>
            </li>
            <li>
              <Link href="/register">Create account</Link>
            </li>
            <li>
              <Link href="/profile">Profile</Link>
            </li>
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10 px-4 py-4 text-center text-xs text-white/50">
        © {new Date().getFullYear()} Suvakamana. Crafted for exceptional local dining.
      </div>
    </footer>
  );
}

export function MobileBottomNav() {
  const pathname = usePathname();
  const items = [
    { href: "/", label: "Home" },
    { href: "/restaurants", label: "Browse" },
    { href: "/cart", label: "Cart" },
    { href: "/profile", label: "Account" },
  ];

  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--border-subtle)] bg-[var(--surface-glass)] backdrop-blur-md md:hidden"
      aria-label="Bottom"
    >
      <ul className="mx-auto grid max-w-lg grid-cols-4">
        {items.map((item) => {
          const active =
            item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
          return (
            <li key={item.href}>
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
