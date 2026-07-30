"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { changePasswordSchema } from "../schemas";

export function ChangePasswordForm() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [success, setSuccess] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSuccess(null);
    setFormError(null);
    setFieldErrors({});
    const parsed = changePasswordSchema.safeParse({
      current_password: currentPassword,
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
      const res = await authApi.changePassword(parsed.data);
      setSuccess(res.message);
      setCurrentPassword("");
      setPassword("");
      setPasswordConfirmation("");
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
        setFormError("Unable to change password.");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="space-y-4" onSubmit={onSubmit} noValidate>
      <Field label="Current password" htmlFor="current_password" error={fieldErrors.current_password}>
        <Input
          id="current_password"
          type="password"
          autoComplete="current-password"
          value={currentPassword}
          onChange={(e) => setCurrentPassword(e.target.value)}
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
        label="Confirm new password"
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
      {formError ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {formError}
        </p>
      ) : null}
      {success ? (
        <p className="text-sm text-[var(--color-success,#2f6b4f)]" role="status">
          {success}
        </p>
      ) : null}
      <Button type="submit" loading={loading}>
        Update password
      </Button>
    </form>
  );
}
