"use client";

import { createContext, useCallback, useContext, useMemo, useState } from "react";
import { AnimatePresence, motion } from "framer-motion";
import { cn } from "@/lib/utils";

type Toast = {
  id: string;
  title: string;
  description?: string;
  tone?: "success" | "error" | "info";
};

type ToastContextValue = {
  push: (toast: Omit<Toast, "id">) => void;
};

const ToastContext = createContext<ToastContextValue | null>(null);

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const push = useCallback((toast: Omit<Toast, "id">) => {
    const id = crypto.randomUUID();
    setToasts((prev) => [...prev, { ...toast, id }]);
    window.setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 3200);
  }, []);

  const value = useMemo(() => ({ push }), [push]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[min(100%-2rem,22rem)] flex-col gap-2">
        <AnimatePresence>
          {toasts.map((toast) => (
            <motion.div
              key={toast.id}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: 8 }}
              className={cn(
                "pointer-events-auto rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-4 shadow-[var(--shadow-md)]",
                toast.tone === "error" && "border-[rgba(163,59,45,0.3)]",
                toast.tone === "success" && "border-[rgba(47,107,79,0.3)]",
              )}
              role="status"
            >
              <p className="text-sm font-semibold text-[var(--text-primary)]">{toast.title}</p>
              {toast.description ? (
                <p className="mt-1 text-xs text-[var(--text-secondary)]">{toast.description}</p>
              ) : null}
            </motion.div>
          ))}
        </AnimatePresence>
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error("useToast must be used within ToastProvider");
  return ctx;
}

export function Tabs({
  tabs,
  value,
  onChange,
}: {
  tabs: { id: string; label: string }[];
  value: string;
  onChange: (id: string) => void;
}) {
  return (
    <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-[var(--border-subtle)]">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          role="tab"
          aria-selected={value === tab.id}
          className={cn(
            "whitespace-nowrap px-4 py-3 text-sm font-medium transition",
            value === tab.id
              ? "border-b-2 border-[var(--color-burnt-orange)] text-[var(--color-burnt-orange)]"
              : "text-[var(--text-muted)] hover:text-[var(--text-primary)]",
          )}
          onClick={() => onChange(tab.id)}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

export function Breadcrumbs({
  items,
  className,
}: {
  items: { label: string; href?: string }[];
  className?: string;
}) {
  return (
    <nav aria-label="Breadcrumb" className={cn("text-sm text-[var(--text-muted)]", className)}>
      <ol className="flex flex-wrap items-center gap-2">
        {items.map((item, index) => (
          <li key={item.label} className="inline-flex items-center gap-2">
            {index > 0 ? <span aria-hidden>/</span> : null}
            {item.href ? (
              <a href={item.href} className="hover:opacity-100">
                {item.label}
              </a>
            ) : (
              <span className="opacity-100">{item.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function Pagination({
  page,
  totalPages,
  onChange,
}: {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-4">
      <p className="text-sm text-[var(--text-muted)]">
        Page {page} of {totalPages}
      </p>
      <div className="flex gap-2">
        <button
          type="button"
          className="rounded-[var(--radius-md)] border border-[var(--border-strong)] px-3 py-1.5 text-sm disabled:opacity-40"
          disabled={page <= 1}
          onClick={() => onChange(page - 1)}
        >
          Previous
        </button>
        <button
          type="button"
          className="rounded-[var(--radius-md)] border border-[var(--border-strong)] px-3 py-1.5 text-sm disabled:opacity-40"
          disabled={page >= totalPages}
          onClick={() => onChange(page + 1)}
        >
          Next
        </button>
      </div>
    </div>
  );
}

export function Tooltip({
  content,
  children,
}: {
  content: string;
  children: React.ReactNode;
}) {
  return (
    <span className="group relative inline-flex">
      {children}
      <span className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 -translate-x-1/2 rounded bg-[var(--color-warm-black)] px-2 py-1 text-xs whitespace-nowrap text-white opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
        {content}
      </span>
    </span>
  );
}

export function DropdownMenu({
  label,
  items,
}: {
  label: string;
  items: { label: string; onSelect: () => void }[];
}) {
  const [open, setOpen] = useState(false);
  return (
    <div className="relative">
      <button
        type="button"
        className="rounded-[var(--radius-md)] border border-[var(--border-strong)] bg-white px-3 py-2 text-sm"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        {label}
      </button>
      {open ? (
        <div
          role="menu"
          className="absolute right-0 z-20 mt-2 min-w-44 overflow-hidden rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-white shadow-[var(--shadow-md)]"
        >
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              className="block w-full px-3 py-2 text-left text-sm hover:bg-[var(--surface-muted)]"
              onClick={() => {
                item.onSelect();
                setOpen(false);
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}
