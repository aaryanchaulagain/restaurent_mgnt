"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import type { LoginPortal } from "../api/auth-api";
import { useAuth } from "../hooks/use-auth";
import { loginSchema } from "../schemas";
import { defaultRedirectForUser, intendedFromSearch, safeRedirectPath } from "../utils/redirects";

export function LoginForm({ portal = "standard" }: { portal?: LoginPortal }) {
  const { login } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    setFieldErrors({});

    const parsed = loginSchema.safeParse({ email, password, remember });
    if (!parsed.success) {
      const next: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = String(issue.path[0] ?? "form");
        if (!next[key]) next[key] = issue.message;
      }
      setFieldErrors(next);
      return;
    }

    setLoading(true);
    try {
      const result = await login(parsed.data, portal);
      if (result.mfaRequired) {
        router.push("/mfa/challenge");
        return;
      }
      if (!result.user) {
        setFormError("Unable to sign in. Please try again.");
        return;
      }
      const intended = safeRedirectPath(
        intendedFromSearch(searchParams.toString()),
        defaultRedirectForUser(result.user),
      );
      router.replace(intended);
    } catch (error) {
      if (error instanceof ApiError) {
        const message = error.message.toLowerCase();
        if (message.includes("suspend")) {
          router.push("/account/suspended");
          return;
        }
        if (message.includes("lock")) {
          router.push("/account/locked");
          return;
        }
        if (error.errors) {
          const next: Record<string, string> = {};
          for (const [key, messages] of Object.entries(error.errors)) {
            next[key] = messages[0] ?? error.message;
          }
          setFieldErrors(next);
        }
        setFormError(error.message || "Invalid email or password.");
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="space-y-4" onSubmit={onSubmit} noValidate>
      <Field label="Email" htmlFor="email" error={fieldErrors.email}>
        <Input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="you@example.com"
          aria-invalid={Boolean(fieldErrors.email)}
          disabled={loading}
        />
      </Field>

      <Field label="Password" htmlFor="password" error={fieldErrors.password}>
        <div className="relative">
          <Input
            id="password"
            name="password"
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="••••••••"
            aria-invalid={Boolean(fieldErrors.password)}
            disabled={loading}
            className="pr-11"
          />
          <button
            type="button"
            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
            onClick={() => setShowPassword((v) => !v)}
            aria-label={showPassword ? "Hide password" : "Show password"}
          >
            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
      </Field>

      <div className="flex items-center justify-between gap-3">
        <Checkbox
          label="Remember me"
          checked={remember}
          onChange={(e) => setRemember(e.target.checked)}
          disabled={loading}
        />
        <Link
          href="/forgot-password"
          className="text-sm font-semibold text-[var(--color-burnt-orange)]"
        >
          Forgot password?
        </Link>
      </div>

      {formError ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {formError}
        </p>
      ) : null}

      <Button type="submit" className="w-full" loading={loading}>
        {portal === "super_admin" ? "Sign in to super admin" : "Sign in"}
      </Button>
    </form>
  );
}
