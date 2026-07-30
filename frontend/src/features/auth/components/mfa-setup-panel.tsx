"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { useAuth } from "../hooks/use-auth";

export function MfaSetupPanel() {
  const { refresh } = useAuth();
  const [secret, setSecret] = useState<string | null>(null);
  const [qrSvg, setQrSvg] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [bootLoading, setBootLoading] = useState(true);

  useEffect(() => {
    void (async () => {
      try {
        const res = await authApi.mfaSetup();
        setSecret(res.data.secret);
        setQrSvg(res.data.qr_svg);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Unable to start MFA setup.");
      } finally {
        setBootLoading(false);
      }
    })();
  }, []);

  async function confirm(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await authApi.mfaConfirm(code.trim());
      setRecoveryCodes(res.data.recovery_codes);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Invalid confirmation code.");
    } finally {
      setLoading(false);
    }
  }

  if (bootLoading) {
    return <p className="text-sm text-[var(--text-secondary)]">Preparing authenticator setup…</p>;
  }

  if (recoveryCodes) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-[var(--text-secondary)]">
          MFA is enabled. Store these recovery codes in a safe place. Each code can be used once.
        </p>
        <ul className="grid gap-2 rounded-[var(--radius-md)] bg-[var(--surface-muted)] p-4 font-mono text-sm">
          {recoveryCodes.map((c) => (
            <li key={c}>{c}</li>
          ))}
        </ul>
        <Button type="button" className="w-full" onClick={() => (window.location.href = "/admin/dashboard")}>
          Continue to dashboard
        </Button>
      </div>
    );
  }

  return (
    <form className="space-y-4" onSubmit={confirm}>
      {qrSvg ? (
        <div
          className="flex justify-center [&_svg]:h-48 [&_svg]:w-48"
          dangerouslySetInnerHTML={{ __html: qrSvg }}
          aria-label="Authenticator QR code"
        />
      ) : null}
      {secret ? (
        <p className="break-all text-center text-xs text-[var(--text-muted)]">
          Manual key: <span className="font-mono">{secret}</span>
        </p>
      ) : null}
      <Field label="Confirmation code" htmlFor="code" error={error ?? undefined}>
        <Input
          id="code"
          inputMode="numeric"
          autoComplete="one-time-code"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          disabled={loading}
        />
      </Field>
      <Button type="submit" className="w-full" loading={loading}>
        Confirm and enable MFA
      </Button>
    </form>
  );
}
