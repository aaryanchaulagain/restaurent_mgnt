import Link from "next/link";

export function SiteFooter() {
  return (
    <footer className="mt-auto border-t border-[var(--border-subtle)] bg-[var(--color-warm-black)] text-[var(--text-inverse)]">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-4">
        <div className="md:col-span-2">
          <p className="font-[family-name:var(--font-display)] text-3xl">Khana</p>
          <p className="mt-3 max-w-md text-sm leading-relaxed text-white/70">
            Khana is a marketplace for restaurants, bakeries, butcheries, groceries and other local
            businesses.
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
            Login
          </p>
          <ul className="mt-3 space-y-2 text-sm text-white/75">
            <li>
              <Link href="/login">Login</Link>
            </li>
            <li>
              <Link href="/register">Create account</Link>
            </li>
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10 px-4 py-4 text-center text-xs text-white/50">
        © {new Date().getFullYear()} Khana. Local food, groceries and specialty shops.
      </div>
    </footer>
  );
}
