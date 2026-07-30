"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { forgotPasswordSchema } from "../schemas";

export function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSuccess(null);
    const parsed = forgotPasswordSchema.safeParse({ email });
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? "Invalid email");
      return;
    }
    setLoading(true);
    try {
      const res = await authApi.forgotPassword(parsed.data);
      setSuccess(res.message);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to send reset email.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="space-y-4" onSubmit={onSubmit} noValidate>
      <Field label="Email" htmlFor="email" error={error && !success ? error : undefined}>
        <Input
          id="email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          disabled={loading}
        />
      </Field>
      {success ? (
        <p className="text-sm text-[var(--color-success,#2f6b4f)]" role="status">
          {success}
        </p>
      ) : null}
      <Button type="submit" className="w-full" loading={loading}>
        Send reset link
      </Button>
    </form>
  );
}
