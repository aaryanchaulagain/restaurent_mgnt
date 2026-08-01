"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { ApiError } from "@/lib/api/client";
import { authApi, type LoginPortal } from "../api/auth-api";
import type { LoginInput, RegisterInput } from "../schemas";
import type { AuthStatus, AuthUser } from "../types";
import { hasPermission, hasRole, isAccountBlocked } from "../utils/roles";

type AuthContextValue = {
  user: AuthUser | null;
  status: AuthStatus;
  isAuthenticated: boolean;
  isLoading: boolean;
  refresh: () => Promise<AuthUser | null>;
  login: (
    input: LoginInput,
    portal?: LoginPortal,
  ) => Promise<{ mfaRequired: boolean; user: AuthUser | null }>;
  register: (input: RegisterInput) => Promise<AuthUser>;
  logout: () => Promise<void>;
  logoutAll: () => Promise<void>;
  setUser: (user: AuthUser | null) => void;
  hasRole: (role: string) => boolean;
  hasPermission: (permission: string) => boolean;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  const refresh = useCallback(async () => {
    try {
      // GET /me does not need a CSRF cookie; skip the extra Sanctum round-trip.
      const res = await authApi.me();
      setUser(res.data.user);
      setStatus("authenticated");
      return res.data.user;
    } catch (error) {
      if (error instanceof ApiError && [401, 403, 419].includes(error.status)) {
        setUser(null);
        setStatus("guest");
        return null;
      }
      if (error instanceof ApiError && error.status === 403) {
        const code = error.message.toLowerCase();
        if (code.includes("suspend") || code.includes("disabled")) {
          window.location.assign("/account/suspended");
        } else if (code.includes("lock")) {
          window.location.assign("/account/locked");
        }
      }
      setUser(null);
      setStatus("guest");
      return null;
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    queueMicrotask(() => {
      if (cancelled) return;
      void refresh();
    });
    return () => {
      cancelled = true;
    };
  }, [refresh]);

  const login = useCallback(async (input: LoginInput, portal: LoginPortal = "standard") => {
    const res = await authApi.login(input, portal);
    if (res.data.mfa_required) {
      return { mfaRequired: true, user: null };
    }
    const nextUser = res.data.user ?? null;
    setUser(nextUser);
    setStatus(nextUser ? "authenticated" : "guest");
    return { mfaRequired: false, user: nextUser };
  }, []);

  const register = useCallback(
    async (input: RegisterInput) => {
      const res = await authApi.register(input);
      try {
        await refresh();
      } catch {
        /* ignore */
      }
      return res.data.user;
    },
    [refresh],
  );

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const logoutAll = useCallback(async () => {
    try {
      await authApi.logoutAll();
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      status,
      isAuthenticated: status === "authenticated" && Boolean(user) && !isAccountBlocked(user),
      isLoading: status === "loading",
      refresh,
      login,
      register,
      logout,
      logoutAll,
      setUser,
      hasRole: (role) => hasRole(user, role),
      hasPermission: (permission) => hasPermission(user, permission),
    }),
    [user, status, refresh, login, register, logout, logoutAll],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within AuthProvider");
  }
  return ctx;
}

export function useCurrentUser() {
  const { user, isLoading, status } = useAuth();
  return { user, isLoading, status };
}

export function usePermissions() {
  const { user, hasPermission: can, hasRole: role } = useAuth();
  return {
    permissions: user?.permissions ?? [],
    roles: user?.roles ?? [],
    can,
    hasRole: role,
  };
}

export function useLogin() {
  const { login, isLoading } = useAuth();
  return { login, isLoading };
}

export function useRegister() {
  const { register, isLoading } = useAuth();
  return { register, isLoading };
}

export function useLogout() {
  const { logout, logoutAll, isLoading } = useAuth();
  return { logout, logoutAll, isLoading };
}
