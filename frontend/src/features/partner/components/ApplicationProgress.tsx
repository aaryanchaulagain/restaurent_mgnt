"use client";

import { WIZARD_STEPS } from "../constants";
import type { ApplicationWizardStep } from "../types";
import { cn } from "@/lib/utils";

export function ApplicationProgress({
  currentStep,
}: {
  currentStep: ApplicationWizardStep;
}) {
  const currentIndex = WIZARD_STEPS.findIndex((s) => s.id === currentStep);

  return (
    <nav aria-label="Application progress" className="mb-8">
      <ol className="flex flex-wrap gap-2">
        {WIZARD_STEPS.map((step, index) => {
          const done = index < currentIndex;
          const active = step.id === currentStep;
          return (
            <li key={step.id}>
              <span
                className={cn(
                  "inline-flex items-center rounded-full px-3 py-1 text-xs font-medium transition",
                  done && "bg-[var(--color-burnt-orange)]/15 text-[var(--color-burnt-orange)]",
                  active &&
                    "bg-[var(--color-burnt-orange)] text-white shadow-[var(--shadow-sm)]",
                  !done &&
                    !active &&
                    "border border-[var(--border-subtle)] bg-white text-[var(--text-muted)]",
                )}
              >
                {index + 1}. {step.label}
              </span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
