"use client";

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/client";
import { authApi } from "../api/auth-api";
import type { AuthSession } from "../types";
import { useLogout } from "../hooks/use-auth";

export function SessionsPanel() {
  const { logoutAll } = useLogout();
  const [sessions, setSessions] = useState<AuthSession[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | "others" | "all" | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await authApi.sessions();
      setSessions(res.data.sessions);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to load sessions.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) void load();
    });
    return () => {
      cancelled = true;
    };
  }, [load]);

  async function revoke(id: number) {
    setBusyId(id);
    try {
      await authApi.revokeSession(id);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to revoke session.");
    } finally {
      setBusyId(null);
    }
  }

  async function revokeOthers() {
    setBusyId("others");
    try {
      await authApi.revokeOtherSessions();
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to revoke sessions.");
    } finally {
      setBusyId(null);
    }
  }

  async function logoutEverywhere() {
    setBusyId("all");
    try {
      await logoutAll();
      window.location.assign("/login");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Unable to log out.");
      setBusyId(null);
    }
  }

  if (loading) {
    return <p className="text-sm text-[var(--text-secondary)]">Loading sessions…</p>;
  }

  return (
    <div className="space-y-4">
      {error ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {error}
        </p>
      ) : null}
      <ul className="space-y-3">
        {sessions.map((session) => (
          <li
            key={session.id}
            className="flex flex-wrap items-start justify-between gap-3 rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3"
          >
            <div>
              <p className="text-sm font-semibold">
                {session.device_label ?? "Unknown device"}
                {session.is_current ? (
                  <span className="ml-2 text-xs font-medium text-[var(--color-burnt-orange)]">
                    Current
                  </span>
                ) : null}
              </p>
              <p className="mt-1 text-xs text-[var(--text-muted)]">
                {session.ip_address ?? "IP hidden"} · Last active{" "}
                {session.last_activity_at
                  ? new Date(session.last_activity_at).toLocaleString()
                  : "—"}
              </p>
            </div>
            {!session.is_current ? (
              <Button
                type="button"
                variant="outline"
                size="sm"
                loading={busyId === session.id}
                onClick={() => void revoke(session.id)}
              >
                Revoke
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="secondary"
          loading={busyId === "others"}
          onClick={() => void revokeOthers()}
        >
          Log out other sessions
        </Button>
        <Button
          type="button"
          variant="destructive"
          loading={busyId === "all"}
          onClick={() => void logoutEverywhere()}
        >
          Log out everywhere
        </Button>
      </div>
    </div>
  );
}
