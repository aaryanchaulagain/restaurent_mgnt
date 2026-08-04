"use client";

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { AuthPageShell } from "@/features/auth/components/auth-page-shell";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { ApiError } from "@/lib/api/client";
import {
  businessBranchApi,
  invitationErrorMessage,
} from "@/features/business/api/business-branch-api";
import { setBranchDashboardContext } from "@/features/business/lib/branch-context";

function AcceptInvitationInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const router = useRouter();
  const { user, status, refresh, setUser } = useAuth();

  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<{
    existing_user: boolean;
    invitation: {
      email: string;
      full_name: string | null;
      role: string;
      expires_at: string | null;
      branch: { public_id: string; name: string };
      business: { public_id: string; name: string };
    };
  } | null>(null);

  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");

  useEffect(() => {
    let cancelled = false;
    if (!token) {
      setError("This invitation link is invalid.");
      setLoading(false);
      return;
    }

    void (async () => {
      try {
        const res = await businessBranchApi.previewBranchInvitation(token);
        if (cancelled) return;
        setPreview(res.data);
        if (res.data.invitation.full_name) {
          const parts = res.data.invitation.full_name.trim().split(/\s+/, 2);
          setFirstName(parts[0] ?? "");
          setLastName(parts[1] ?? "");
        }
      } catch (err) {
        if (cancelled) return;
        setError(
          err instanceof ApiError
            ? invitationErrorMessage(err)
            : "This invitation link is invalid or has expired.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [token]);

  async function accept() {
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await businessBranchApi.acceptBranchInvitation(token, {
        first_name: firstName || undefined,
        last_name: lastName || undefined,
        password: password || undefined,
        password_confirmation: passwordConfirmation || undefined,
      });
      const nextUser = await refresh();
      if (nextUser) {
        setUser(nextUser);
      }
      setBranchDashboardContext({
        businessPublicId: res.data.branch.business_public_id ?? null,
        branchPublicId: res.data.branch.public_id,
        restaurantPublicId: res.data.branch.restaurant_public_id ?? null,
        aggregate: false,
      });
      router.replace("/restaurant/dashboard");
    } catch (err) {
      setError(
        err instanceof ApiError ? invitationErrorMessage(err) : "Unable to accept invitation.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  if (loading || status === "loading") {
    return <p className="text-sm text-[var(--text-secondary)]">Loading invitation…</p>;
  }

  if (error && !preview) {
    return (
      <div className="space-y-4 text-sm">
        <p className="text-[var(--color-error)]" role="alert">
          {error}
        </p>
        <Link href="/login" className="font-semibold text-[var(--color-burnt-orange)]">
          Back to sign in
        </Link>
      </div>
    );
  }

  if (!preview) {
    return null;
  }

  const invitedEmail = preview.invitation.email;
  const loggedInWrongUser =
    Boolean(user) && user!.email.toLowerCase() !== invitedEmail.toLowerCase();
  const needsLogin = preview.existing_user && (!user || loggedInWrongUser);
  const canCreateAccount = !preview.existing_user;

  return (
    <div className="space-y-4">
      <div className="rounded-[var(--radius-md)] bg-[var(--surface-sunken,#f7f4ef)] p-4 text-sm">
        <p>
          <strong>{preview.invitation.business.name}</strong> invited you to manage{" "}
          <strong>{preview.invitation.branch.name}</strong> as{" "}
          {preview.invitation.role.replaceAll("_", " ")}.
        </p>
        <p className="mt-2 text-[var(--text-secondary)]">
          Invited email: {invitedEmail}
          {preview.invitation.expires_at
            ? ` · Expires ${new Date(preview.invitation.expires_at).toLocaleString()}`
            : null}
        </p>
      </div>

      {error ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {error}
        </p>
      ) : null}

      {needsLogin ? (
        <div className="space-y-3 text-sm">
          <p>
            An account already exists for this email. Sign in as <strong>{invitedEmail}</strong>{" "}
            to accept.
          </p>
          <Link
            href={`/login?next=${encodeURIComponent(`/branch-invitations/accept?token=${token}`)}`}
            className="inline-flex h-11 items-center justify-center rounded-[var(--radius-md)] bg-[var(--color-burnt-orange)] px-5 text-sm font-medium text-white"
          >
            Sign in to accept
          </Link>
        </div>
      ) : null}

      {!needsLogin && preview.existing_user ? (
        <Button type="button" className="w-full" loading={submitting} onClick={() => void accept()}>
          Accept invitation
        </Button>
      ) : null}

      {canCreateAccount ? (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void accept();
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="First name">
              <Input value={firstName} onChange={(e) => setFirstName(e.target.value)} required />
            </Field>
            <Field label="Last name">
              <Input value={lastName} onChange={(e) => setLastName(e.target.value)} />
            </Field>
          </div>
          <Field label="Password">
            <Input
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </Field>
          <Field label="Confirm password">
            <Input
              type="password"
              autoComplete="new-password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
            />
          </Field>
          <Button type="submit" className="w-full" loading={submitting}>
            Create password and accept
          </Button>
        </form>
      ) : null}
    </div>
  );
}

export default function BranchInvitationAcceptPage() {
  return (
    <AuthPageShell
      title="Accept branch invitation"
      subtitle="Join your assigned Khana branch securely."
    >
      <Suspense fallback={<p className="text-sm text-[var(--text-secondary)]">Loading…</p>}>
        <AcceptInvitationInner />
      </Suspense>
    </AuthPageShell>
  );
}
