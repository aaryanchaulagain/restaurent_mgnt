"use client";

import { AnimatePresence, motion } from "framer-motion";
import { X } from "lucide-react";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function Modal({
  open,
  onClose,
  title,
  children,
  className,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  className?: string;
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [open, onClose]);

  return (
    <AnimatePresence>
      {open ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-6">
          <motion.button
            type="button"
            aria-label="Close dialog overlay"
            className="absolute inset-0 bg-[var(--surface-glass-dark)] backdrop-blur-sm"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
          />
          <motion.div
            role="dialog"
            aria-modal="true"
            aria-label={title}
            className={cn(
              "relative z-10 max-h-[92vh] w-full overflow-y-auto rounded-t-[var(--radius-xl)] bg-[var(--surface-elevated)] shadow-[var(--shadow-lg)] sm:max-w-lg sm:rounded-[var(--radius-xl)]",
              className,
            )}
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 16 }}
            transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
          >
            <div className="sticky top-0 flex items-center justify-between border-b border-[var(--border-subtle)] bg-[var(--surface-elevated)] px-5 py-4">
              <h2 className="font-[family-name:var(--font-display)] text-xl">{title}</h2>
              <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
                <X className="h-5 w-5" />
              </Button>
            </div>
            <div className="p-5">{children}</div>
          </motion.div>
        </div>
      ) : null}
    </AnimatePresence>
  );
}

export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  destructive,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description: string;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
}) {
  return (
    <Modal open={open} onClose={onClose} title={title} className="sm:max-w-md">
      <p className="text-sm leading-relaxed text-[var(--text-secondary)]">{description}</p>
      <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <Button variant="secondary" onClick={onClose}>
          {cancelLabel}
        </Button>
        <Button
          variant={destructive ? "destructive" : "primary"}
          onClick={() => {
            onConfirm();
            onClose();
          }}
        >
          {confirmLabel}
        </Button>
      </div>
    </Modal>
  );
}

export function Drawer({
  open,
  onClose,
  title,
  children,
  side = "right",
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  side?: "right" | "left";
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [open, onClose]);

  return (
    <AnimatePresence>
      {open ? (
        <div className="fixed inset-0 z-50">
          <motion.button
            type="button"
            aria-label="Close drawer overlay"
            className="absolute inset-0 bg-[var(--surface-glass-dark)] backdrop-blur-sm"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
          />
          <motion.aside
            role="dialog"
            aria-modal="true"
            aria-label={title}
            className={cn(
              "absolute top-0 flex h-full w-full max-w-md flex-col bg-[var(--surface-elevated)] shadow-[var(--shadow-lg)]",
              side === "right" ? "right-0" : "left-0",
            )}
            initial={{ x: side === "right" ? "100%" : "-100%" }}
            animate={{ x: 0 }}
            exit={{ x: side === "right" ? "100%" : "-100%" }}
            transition={{ duration: 0.32, ease: [0.22, 1, 0.36, 1] }}
          >
            <div className="flex items-center justify-between border-b border-[var(--border-subtle)] px-5 py-4">
              <h2 className="font-[family-name:var(--font-display)] text-xl">{title}</h2>
              <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
                <X className="h-5 w-5" />
              </Button>
            </div>
            <div className="flex-1 overflow-y-auto p-5">{children}</div>
          </motion.aside>
        </div>
      ) : null}
    </AnimatePresence>
  );
}
