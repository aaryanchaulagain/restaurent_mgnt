"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import { useAuth } from "../hooks/use-auth";

export function VerifyEmailNotice() {
  const { user, refresh } = useAuth();
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) void refresh();
    });
    return () => {
      cancelled = true;
    };
  }, [refresh]);

  async function resend() {
    setLoading(true);
    setError(null);
    setMessage(null);
    try {
      const res = await authApi.resendVerification();
      setMessage(res.message);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to resend verification.");
    } finally {
      setLoading(false);
    }
  }

  if (user?.email_verified_at) {
    return (
      <div className="space-y-4 text-sm">
        <p>Your email is verified.</p>
        <Link
          href="/profile"
          className="inline-flex h-11 items-center justify-center rounded-[var(--radius-md)] bg-[var(--color-burnt-orange)] px-5 text-sm font-medium text-white"
        >
          Continue to profile
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-[var(--text-secondary)]">
        We sent a verification link to {user?.email ?? "your email"}. Open the link to activate
        your account.
      </p>
      {message ? (
        <p className="text-sm text-[var(--color-success,#2f6b4f)]" role="status">
          {message}
        </p>
      ) : null}
      {error ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {error}
        </p>
      ) : null}
      <Button type="button" loading={loading} onClick={() => void resend()} className="w-full">
        Resend verification email
      </Button>
    </div>
  );
}

function VerifyEmailHandlerInner() {
  const searchParams = useSearchParams();
  const verifyUrl = searchParams.get("verify_url");
  const { refresh } = useAuth();
  const [state, setState] = useState<"loading" | "success" | "error">(
    verifyUrl ? "loading" : "error",
  );
  const [message, setMessage] = useState(
    verifyUrl
      ? "Verifying your email…"
      : "This verification link is invalid or has expired.",
  );

  useEffect(() => {
    if (!verifyUrl) return;
    let cancelled = false;
    queueMicrotask(() => {
      void (async () => {
        try {
          const res = await authApi.verifyEmailFromSignedUrl(verifyUrl);
          if (cancelled) return;
          setState("success");
          setMessage(res.message || "Email verified successfully.");
          await refresh();
        } catch {
          if (cancelled) return;
          setState("error");
          setMessage("This verification link is invalid or has expired.");
        }
      })();
    });
    return () => {
      cancelled = true;
    };
  }, [verifyUrl, refresh]);

  return (
    <div className="space-y-4 text-sm">
      <p
        className={
          state === "error" ? "text-[var(--color-error)]" : "text-[var(--text-secondary)]"
        }
        role={state === "error" ? "alert" : "status"}
      >
        {message}
      </p>
      {state === "success" ? (
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Continue to sign in
        </Link>
      ) : null}
      {state === "error" ? (
        <Link href="/verify-email" className="font-semibold text-[var(--color-burnt-orange)]">
          Request a new link
        </Link>
      ) : null}
    </div>
  );
}

export function VerifyEmailHandler() {
  return (
    <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Verifying…</p>}>
      <VerifyEmailHandlerInner />
    </Suspense>
  );
}

function VerifyEmailPageInner() {
  const searchParams = useSearchParams();
  const hasVerifyUrl = Boolean(searchParams.get("verify_url"));
  return hasVerifyUrl ? <VerifyEmailHandler /> : <VerifyEmailNotice />;
}

export function VerifyEmailPageContent() {
  return (
    <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Loading…</p>}>
      <VerifyEmailPageInner />
    </Suspense>
  );
}
