"use client";

import type { InputHTMLAttributes, ReactNode, TextareaHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

type FieldProps = {
  label?: string;
  error?: string;
  hint?: string;
  className?: string;
  children: ReactNode;
  htmlFor?: string;
};

export function Field({ label, error, hint, className, children, htmlFor }: FieldProps) {
  return (
    <div className={cn("flex flex-col gap-2", className)}>
      {label ? (
        <label
          htmlFor={htmlFor}
          className="text-sm font-medium text-[var(--text-primary)]"
        >
          {label}
        </label>
      ) : null}
      {children}
      {hint && !error ? (
        <p className="text-xs text-[var(--text-muted)]">{hint}</p>
      ) : null}
      {error ? (
        <p className="text-xs text-[var(--color-error)]" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

const controlClass =
  "w-full rounded-[var(--radius-md)] border border-[var(--border-strong)] bg-[var(--surface-elevated)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] shadow-[var(--shadow-sm)] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-burnt-orange)] disabled:opacity-50";

export function Input({
  className,
  ...props
}: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={cn(controlClass, className)} {...props} />;
}

export function Textarea({
  className,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <textarea
      className={cn(controlClass, "min-h-28 resize-y", className)}
      {...props}
    />
  );
}

export function Select({
  className,
  children,
  ...props
}: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className={cn(controlClass, className)} {...props}>
      {children}
    </select>
  );
}

export function Checkbox({
  label,
  className,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className={cn("inline-flex items-center gap-2 text-sm", className)}>
      <input
        type="checkbox"
        className="h-4 w-4 rounded border-[var(--border-strong)] text-[var(--color-burnt-orange)] focus-visible:ring-[var(--color-burnt-orange)]"
        {...props}
      />
      <span>{label}</span>
    </label>
  );
}

export function Radio({
  label,
  className,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className={cn("inline-flex items-center gap-2 text-sm", className)}>
      <input
        type="radio"
        className="h-4 w-4 border-[var(--border-strong)] text-[var(--color-burnt-orange)] focus-visible:ring-[var(--color-burnt-orange)]"
        {...props}
      />
      <span>{label}</span>
    </label>
  );
}

export function Switch({
  label,
  checked,
  onChange,
  id,
}: {
  label: string;
  checked?: boolean;
  onChange?: (checked: boolean) => void;
  id?: string;
}) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-3 text-sm">
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-label={label}
        onClick={() => onChange?.(!checked)}
        className={cn(
          "relative h-6 w-11 rounded-full transition",
          checked ? "bg-[var(--color-burnt-orange)]" : "bg-[var(--border-strong)]",
        )}
      >
        <span
          className={cn(
            "absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition",
            checked && "translate-x-5",
          )}
        />
      </button>
      <span>{label}</span>
    </label>
  );
}

export function SearchInput({
  className,
  ...props
}: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className={cn("relative", className)}>
      <Input
        className="pl-10"
        type="search"
        aria-label={props["aria-label"] ?? "Search"}
        {...props}
      />
      <svg
        aria-hidden
        className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
      >
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
      </svg>
    </div>
  );
}

export function FileUpload({
  label = "Upload image",
  hint = "PNG or JPG up to 5MB",
  accept = "image/png,image/jpeg,image/webp,application/pdf",
  onChange,
  disabled,
  fileName,
  name,
}: {
  label?: string;
  hint?: string;
  accept?: string;
  onChange?: (file: File | null) => void;
  disabled?: boolean;
  fileName?: string | null;
  name?: string;
}) {
  return (
    <label
      className={cn(
        "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[var(--radius-lg)] border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-6 py-10 text-center transition hover:border-[var(--color-copper)]",
        disabled && "pointer-events-none opacity-50",
      )}
    >
      <span className="text-sm font-medium text-[var(--text-primary)]">{label}</span>
      <span className="text-xs text-[var(--text-muted)]">
        {fileName ? `Selected: ${fileName}` : hint}
      </span>
      <input
        type="file"
        name={name}
        className="sr-only"
        accept={accept}
        disabled={disabled}
        onChange={(e) => {
          const file = e.target.files?.[0] ?? null;
          onChange?.(file);
        }}
      />
    </label>
  );
}
