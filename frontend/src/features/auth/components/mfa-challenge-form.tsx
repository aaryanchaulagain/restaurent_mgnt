"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { useAuth } from "../hooks/use-auth";
import { defaultRedirectForUser } from "../utils/redirects";

export function MfaChallengeForm({ recovery = false }: { recovery?: boolean }) {
  const { setUser } = useAuth();
  const router = useRouter();
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (!code.trim()) {
      setError("Enter your authentication code");
      return;
    }
    setLoading(true);
    try {
      const res = recovery
        ? await authApi.mfaRecovery(code.trim())
        : await authApi.mfaChallenge(code.trim());
      setUser(res.data.user);
      router.replace(defaultRedirectForUser(res.data.user));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Invalid code.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="space-y-4" onSubmit={onSubmit} noValidate>
      <Field
        label={recovery ? "Recovery code" : "Authenticator code"}
        htmlFor="code"
        error={error ?? undefined}
        hint={recovery ? "Use a one-time recovery code" : "6-digit code from your authenticator app"}
      >
        <Input
          id="code"
          inputMode={recovery ? "text" : "numeric"}
          autoComplete="one-time-code"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          disabled={loading}
        />
      </Field>
      <Button type="submit" className="w-full" loading={loading}>
        Verify
      </Button>
    </form>
  );
}
