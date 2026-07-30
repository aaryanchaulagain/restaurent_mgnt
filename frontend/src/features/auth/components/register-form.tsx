"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { useAuth } from "../hooks/use-auth";
import { registerSchema } from "../schemas";
import { passwordStrength } from "../utils/roles";

export function RegisterForm() {
  const { register } = useAuth();
  const router = useRouter();
  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
    terms: false,
  });
  const [showPassword, setShowPassword] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const strength = useMemo(() => passwordStrength(form.password), [form.password]);

  function update<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    setFieldErrors({});

    const parsed = registerSchema.safeParse(form);
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
      await register(parsed.data);
      router.push("/verify-email");
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.errors) {
          const next: Record<string, string> = {};
          for (const [key, messages] of Object.entries(error.errors)) {
            next[key] = messages[0] ?? error.message;
          }
          setFieldErrors(next);
        }
        setFormError(error.message);
      } else {
        setFormError("Unable to create your account. Please try again.");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="space-y-4" onSubmit={onSubmit} noValidate>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="First name" htmlFor="first_name" error={fieldErrors.first_name}>
          <Input
            id="first_name"
            autoComplete="given-name"
            value={form.first_name}
            onChange={(e) => update("first_name", e.target.value)}
            disabled={loading}
          />
        </Field>
        <Field label="Last name" htmlFor="last_name" error={fieldErrors.last_name}>
          <Input
            id="last_name"
            autoComplete="family-name"
            value={form.last_name}
            onChange={(e) => update("last_name", e.target.value)}
            disabled={loading}
          />
        </Field>
      </div>

      <Field label="Email" htmlFor="email" error={fieldErrors.email}>
        <Input
          id="email"
          type="email"
          autoComplete="email"
          value={form.email}
          onChange={(e) => update("email", e.target.value)}
          placeholder="you@example.com"
          disabled={loading}
        />
      </Field>

      <Field label="Phone" htmlFor="phone" error={fieldErrors.phone} hint="Optional">
        <Input
          id="phone"
          type="tel"
          autoComplete="tel"
          value={form.phone}
          onChange={(e) => update("phone", e.target.value)}
          placeholder="+977 9800000000"
          disabled={loading}
        />
      </Field>

      <Field
        label="Password"
        htmlFor="password"
        error={fieldErrors.password}
        hint={form.password ? `Strength: ${strength.label}` : "At least 8 characters with mixed case and a number"}
      >
        <div className="relative">
          <Input
            id="password"
            type={showPassword ? "text" : "password"}
            autoComplete="new-password"
            value={form.password}
            onChange={(e) => update("password", e.target.value)}
            disabled={loading}
            className="pr-11"
          />
          <button
            type="button"
            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-[var(--text-muted)]"
            onClick={() => setShowPassword((v) => !v)}
            aria-label={showPassword ? "Hide password" : "Show password"}
          >
            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
      </Field>

      <Field
        label="Confirm password"
        htmlFor="password_confirmation"
        error={fieldErrors.password_confirmation}
      >
        <Input
          id="password_confirmation"
          type={showPassword ? "text" : "password"}
          autoComplete="new-password"
          value={form.password_confirmation}
          onChange={(e) => update("password_confirmation", e.target.value)}
          disabled={loading}
        />
      </Field>

      <div>
        <Checkbox
          label="I agree to the terms of service and privacy policy"
          checked={form.terms}
          onChange={(e) => update("terms", e.target.checked)}
          disabled={loading}
        />
        {fieldErrors.terms ? (
          <p className="mt-1 text-xs text-[var(--color-error)]" role="alert">
            {fieldErrors.terms}
          </p>
        ) : null}
      </div>

      {formError ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {formError}
        </p>
      ) : null}

      <Button type="submit" className="w-full" loading={loading}>
        Create account
      </Button>
    </form>
  );
}
