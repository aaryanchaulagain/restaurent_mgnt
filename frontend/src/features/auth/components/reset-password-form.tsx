"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { resetPasswordSchema } from "../schemas";

export function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const [email, setEmail] = useState(searchParams.get("email") ?? "");
  const [token] = useState(searchParams.get("token") ?? "");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    setFieldErrors({});
    const parsed = resetPasswordSchema.safeParse({
      email,
      token,
      password,
      password_confirmation: passwordConfirmation,
    });
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
      await authApi.resetPassword(parsed.data);
      router.push("/login?reset=1");
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
        setFormError("Unable to reset password.");
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
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          disabled={loading}
        />
      </Field>
      <Field label="New password" htmlFor="password" error={fieldErrors.password}>
        <Input
          id="password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          disabled={loading}
        />
      </Field>
      <Field
        label="Confirm password"
        htmlFor="password_confirmation"
        error={fieldErrors.password_confirmation}
      >
        <Input
          id="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          disabled={loading}
        />
      </Field>
      {fieldErrors.token ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {fieldErrors.token}
        </p>
      ) : null}
      {formError ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {formError}
        </p>
      ) : null}
      <Button type="submit" className="w-full" loading={loading}>
        Reset password
      </Button>
    </form>
  );
}
