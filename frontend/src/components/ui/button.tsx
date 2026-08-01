import { cva, type VariantProps } from "class-variance-authority";
import { Loader2 } from "lucide-react";
import type { ButtonHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 font-medium transition-all duration-[var(--duration-base)] ease-[var(--ease-out-premium)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-burnt-orange)] focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50",
  {
    variants: {
      variant: {
        primary:
          "bg-[var(--color-burnt-orange)] text-white shadow-[var(--shadow-glow-copper)] hover:scale-[1.02] hover:bg-[#c55a24]",
        secondary:
          "bg-[var(--surface-muted)] text-[var(--text-primary)] border border-[var(--border-strong)] hover:bg-white",
        outline:
          "bg-transparent text-[var(--text-primary)] border border-[var(--border-strong)] hover:border-[var(--color-copper)] hover:text-[var(--color-copper)]",
        destructive:
          "bg-[var(--color-error)] text-white hover:bg-[#8d3227]",
        ghost:
          "bg-transparent text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
      },
      size: {
        sm: "h-9 rounded-[var(--radius-md)] px-3 text-sm",
        md: "h-11 rounded-[var(--radius-md)] px-5 text-sm",
        lg: "h-12 rounded-[var(--radius-md)] px-6 text-base",
        icon: "h-10 w-10 rounded-[var(--radius-md)]",
      },
    },
    defaultVariants: {
      variant: "primary",
      size: "md",
    },
  },
);

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof buttonVariants> & {
    loading?: boolean;
  };

export { buttonVariants };

export function Button({
  className,
  variant,
  size,
  loading,
  disabled,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={disabled || loading}
      {...props}
    >
      {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
      {children}
    </button>
  );
}
