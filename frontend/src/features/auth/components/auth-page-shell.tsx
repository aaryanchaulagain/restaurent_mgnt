import type { ReactNode } from "react";
import Link from "next/link";

export function AuthPageShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-4 py-12 sm:px-6">
      <Link
        href="/"
        className="font-[family-name:var(--font-display)] text-3xl text-[var(--text-primary)]"
      >
        Khana
      </Link>
      <h1 className="mt-4 text-4xl">{title}</h1>
      {subtitle ? (
        <p className="mt-2 text-sm text-[var(--text-secondary)]">{subtitle}</p>
      ) : null}
      <div className="mt-8 rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
        {children}
      </div>
      {footer ? <div className="mt-4 text-center text-sm text-[var(--text-secondary)]">{footer}</div> : null}
    </main>
  );
}
