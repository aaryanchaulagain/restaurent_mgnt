import Link from "next/link";
import {
  MobileBottomNav,
  SiteFooter,
  SiteHeader,
} from "@/components/layout/site-chrome";
import { PublicProviders } from "./providers";

export default function PublicLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <PublicProviders>
      <SiteHeader />
      <div className="flex-1 pb-16 md:pb-0">{children}</div>
      <SiteFooter />
      <MobileBottomNav />
      <div className="sr-only">
        <Link href="/cart">Cart</Link>
      </div>
    </PublicProviders>
  );
}
