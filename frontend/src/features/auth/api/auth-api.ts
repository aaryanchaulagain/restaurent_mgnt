import { apiRequest, type ApiEnvelope } from "@/lib/api/client";
import type { AuthSession, AuthUser } from "../types";
import type {
  ChangePasswordInput,
  ForgotPasswordInput,
  LoginInput,
  RegisterInput,
  ResetPasswordInput,
} from "../schemas";

type UserPayload = { user: AuthUser };
/** `verification_url` is only present when the API runs with APP_ENV=local. */
type VerificationPayload = { verification_url?: string };
type RegisterPayload = UserPayload & VerificationPayload;
type LoginPayload = { user?: AuthUser; mfa_required?: boolean };
type SessionsPayload = { sessions: AuthSession[] };
type MfaSetupPayload = {
  secret: string;
  qr_svg: string;
  otpauth_url: string;
};
type RecoveryPayload = { recovery_codes: string[] };
export type LoginPortal = "standard" | "super_admin";

export const authApi = {
  register(input: RegisterInput) {
    return apiRequest<RegisterPayload>("/api/auth/register", {
      method: "POST",
      body: {
        first_name: input.first_name,
        last_name: input.last_name,
        email: input.email,
        phone: input.phone || null,
        password: input.password,
        password_confirmation: input.password_confirmation,
        terms: input.terms,
      },
    });
  },

  login(input: LoginInput, portal: LoginPortal = "standard") {
    return apiRequest<LoginPayload>("/api/auth/login", {
      method: "POST",
      body: {
        email: input.email,
        password: input.password,
        remember: Boolean(input.remember),
        portal,
      },
    });
  },

  logout() {
    return apiRequest("/api/auth/logout", { method: "POST" });
  },

  logoutAll() {
    return apiRequest("/api/auth/logout-all", { method: "POST" });
  },

  me() {
    return apiRequest<UserPayload>("/api/auth/me");
  },

  forgotPassword(input: ForgotPasswordInput) {
    return apiRequest("/api/auth/forgot-password", {
      method: "POST",
      body: input,
    });
  },

  resetPassword(input: ResetPasswordInput) {
    return apiRequest("/api/auth/reset-password", {
      method: "POST",
      body: input,
    });
  },

  changePassword(input: ChangePasswordInput) {
    return apiRequest("/api/auth/change-password", {
      method: "POST",
      body: input,
    });
  },

  resendVerification() {
    return apiRequest<VerificationPayload>("/api/auth/email/verification-notification", {
      method: "POST",
    });
  },

  verifyEmailFromSignedUrl(verifyUrl: string) {
    // Absolute backend signed URL; fetch with credentials.
    return fetch(verifyUrl, {
      method: "GET",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    }).then(async (response) => {
      const envelope = (await response.json()) as ApiEnvelope;
      if (!response.ok || !envelope.success) {
        throw envelope;
      }
      return envelope;
    });
  },

  sessions() {
    return apiRequest<SessionsPayload>("/api/auth/sessions");
  },

  revokeSession(sessionId: number) {
    return apiRequest(`/api/auth/sessions/${sessionId}`, { method: "DELETE" });
  },

  revokeOtherSessions() {
    return apiRequest("/api/auth/sessions", { method: "DELETE" });
  },

  mfaSetup() {
    return apiRequest<MfaSetupPayload>("/api/auth/mfa/setup", { method: "POST" });
  },

  mfaConfirm(code: string) {
    return apiRequest<RecoveryPayload>("/api/auth/mfa/confirm", {
      method: "POST",
      body: { code },
    });
  },

  mfaChallenge(code: string) {
    return apiRequest<UserPayload>("/api/auth/mfa/challenge", {
      method: "POST",
      body: { code },
    });
  },

  mfaRecovery(code: string) {
    return apiRequest<UserPayload>("/api/auth/mfa/recovery", {
      method: "POST",
      body: { code },
    });
  },

  mfaRegenerateRecoveryCodes(password: string) {
    return apiRequest<RecoveryPayload>("/api/auth/mfa/regenerate-recovery-codes", {
      method: "POST",
      body: { password },
    });
  },

  mfaDisable(password: string) {
    return apiRequest("/api/auth/mfa", {
      method: "DELETE",
      body: { password },
    });
  },
};
