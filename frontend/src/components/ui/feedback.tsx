import { cn } from "@/lib/utils";

export function Badge({
  children,
  tone = "neutral",
  className,
}: {
  children: React.ReactNode;
  tone?: "neutral" | "success" | "warning" | "error" | "accent" | "info";
  className?: string;
}) {
  const tones = {
    neutral: "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
    success: "bg-[rgba(47,107,79,0.12)] text-[var(--color-success)]",
    warning: "bg-[rgba(196,146,42,0.14)] text-[var(--color-warning)]",
    error: "bg-[rgba(163,59,45,0.12)] text-[var(--color-error)]",
    accent: "bg-[rgba(216,102,45,0.12)] text-[var(--color-burnt-orange)]",
    info: "bg-[rgba(74,109,140,0.12)] text-[var(--color-info)]",
  };

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-[var(--radius-sm)] px-2 py-0.5 text-xs font-semibold tracking-wide",
        tones[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        "animate-pulse rounded-[var(--radius-md)] bg-[rgba(22,22,20,0.08)]",
        className,
      )}
      aria-hidden
    />
  );
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center rounded-[var(--radius-xl)] border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-6 py-16 text-center">
      <h3 className="font-[family-name:var(--font-display)] text-2xl text-[var(--text-primary)]">
        {title}
      </h3>
      <p className="mt-2 max-w-md text-sm text-[var(--text-secondary)]">{description}</p>
      {action ? <div className="mt-6">{action}</div> : null}
    </div>
  );
}

export function ErrorState({
  title = "Something went wrong",
  description = "Please try again in a moment.",
  action,
}: {
  title?: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <div
      className="rounded-[var(--radius-xl)] border border-[rgba(163,59,45,0.25)] bg-[rgba(163,59,45,0.06)] px-6 py-10 text-center"
      role="alert"
    >
      <h3 className="font-[family-name:var(--font-display)] text-2xl text-[var(--color-error)]">
        {title}
      </h3>
      <p className="mt-2 text-sm text-[var(--text-secondary)]">{description}</p>
      {action ? <div className="mt-6">{action}</div> : null}
    </div>
  );
}
