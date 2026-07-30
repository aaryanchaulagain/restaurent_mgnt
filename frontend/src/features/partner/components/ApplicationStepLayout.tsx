"use client";

import type { ReactNode } from "react";
import { Button } from "@/components/ui/button";

export function ApplicationStepLayout({
  title,
  description,
  children,
  onBack,
  onNext,
  nextLabel = "Continue",
  backLabel = "Back",
  nextDisabled,
  saving,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  onBack?: () => void;
  onNext?: () => void;
  nextLabel?: string;
  backLabel?: string;
  nextDisabled?: boolean;
  saving?: boolean;
}) {
  return (
    <section className="space-y-4">
      <div>
        <h2 className="text-2xl">{title}</h2>
        {description ? (
          <p className="mt-1 text-sm text-[var(--text-secondary)]">{description}</p>
        ) : null}
      </div>
      <div className="space-y-4">{children}</div>
      <div className="flex flex-wrap gap-3 pt-2">
        {onBack ? (
          <Button type="button" variant="outline" onClick={onBack}>
            {backLabel}
          </Button>
        ) : null}
        {onNext ? (
          <Button type="button" onClick={onNext} disabled={nextDisabled || saving}>
            {saving ? "Saving…" : nextLabel}
          </Button>
        ) : null}
      </div>
    </section>
  );
}
