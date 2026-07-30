export const apiOrigin =
  process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, "") ?? "http://localhost:8000";

/** @deprecated Prefer apiOrigin + path. Kept for Phase 0 health checks. */
export const apiBaseUrl = `${apiOrigin}/api/v1`;

export type ApiEnvelope<T = unknown> = {
  success: boolean;
  message: string;
  code?: string | null;
  data: T;
  meta: Record<string, unknown> | null;
  errors: Record<string, string[]> | null;
};

export class ApiError extends Error {
  status: number;
  code: string | null;
  errors: Record<string, string[]> | null;
  envelope: ApiEnvelope | null;

  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> | null = null,
    envelope: ApiEnvelope | null = null,
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = envelope?.code ?? null;
    this.errors = errors;
    this.envelope = envelope;
  }
}

let csrfInitialized = false;

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Sanctum SPA CSRF requires the API host to match the browser hostname
 * (localhost vs 127.0.0.1). Cookies set on one host are not readable on the other.
 */
export async function ensureCsrf(): Promise<void> {
  if (csrfInitialized && readCookie("XSRF-TOKEN")) return;

  const response = await fetch(`${apiOrigin}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  if (!response.ok) {
    csrfInitialized = false;
    throw new ApiError("Unable to initialize secure session.", response.status);
  }

  // Same-origin proxy / matching host: cookie must be readable for X-XSRF-TOKEN.
  // Cross-host setups (localhost page → 127.0.0.1 API) cannot read the cookie.
  if (typeof document !== "undefined" && !readCookie("XSRF-TOKEN")) {
    csrfInitialized = false;
    throw new ApiError(
      "CSRF cookie unavailable. Use the same hostname for the app and API (both localhost or both 127.0.0.1).",
      419,
    );
  }

  csrfInitialized = true;
}

export function resetCsrf(): void {
  csrfInitialized = false;
}

type RequestOptions = {
  method?: string;
  body?: unknown;
  headers?: Record<string, string>;
  skipCsrf?: boolean;
  /** When set, sends X-Restaurant-Id (super-admin restaurant context). */
  restaurantPublicId?: string | null;
};

function restaurantContextHeader(
  path: string,
  explicit?: string | null,
): Record<string, string> {
  const isRestaurantApi =
    path.includes("/api/v1/restaurant/") || path.includes("/restaurant/");
  if (!isRestaurantApi) return {};

  let publicId = explicit;
  if (publicId === undefined && typeof window !== "undefined") {
    publicId = window.localStorage.getItem("suvakamana_restaurant_context_public_id");
  }
  if (!publicId) return {};
  return { "X-Restaurant-Id": publicId };
}

export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<ApiEnvelope<T>> {
  const { method = "GET", body, headers = {}, skipCsrf = false, restaurantPublicId } = options;

  if (!skipCsrf && method !== "GET" && method !== "HEAD") {
    await ensureCsrf();
  }

  const xsrf = readCookie("XSRF-TOKEN");
  const response = await fetch(`${apiOrigin}${path}`, {
    method,
    credentials: "include",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
      ...restaurantContextHeader(path, restaurantPublicId),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });

  let envelope: ApiEnvelope<T> | null = null;
  try {
    envelope = (await response.json()) as ApiEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (!response.ok || envelope?.success === false) {
    if (response.status === 401 || response.status === 419) {
      resetCsrf();
    }
    if (
      envelope?.code === "RESTAURANT_NOT_FOUND" ||
      envelope?.code === "RESTAURANT_CONTEXT_REQUIRED"
    ) {
      if (typeof window !== "undefined") {
        window.localStorage.removeItem("suvakamana_restaurant_context_public_id");
      }
    }
    throw new ApiError(
      envelope?.code === "RESTAURANT_NOT_FOUND"
        ? "Restaurant context expired. Go to Admin → Menus and click Add item to Suvakamana again."
        : envelope?.code === "RESTAURANT_CONTEXT_REQUIRED"
          ? "No restaurant selected. Go to Admin → Menus and choose a restaurant first."
          : (envelope?.message ?? `Request failed (${response.status})`),
      response.status,
      envelope?.errors ?? null,
      envelope,
    );
  }

  return envelope as ApiEnvelope<T>;
}

export async function apiGet<T>(path: string): Promise<ApiEnvelope<T>> {
  return apiRequest<T>(path.startsWith("/api") ? path : `/api/v1${path}`);
}

export async function apiFormData<T>(
  path: string,
  formData: FormData,
  method: "POST" | "PATCH" = "POST",
): Promise<ApiEnvelope<T>> {
  await ensureCsrf();
  const xsrf = readCookie("XSRF-TOKEN");
  const response = await fetch(`${apiOrigin}${path}`, {
    method,
    credentials: "include",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...restaurantContextHeader(path),
      ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
    },
    body: formData,
    cache: "no-store",
  });

  let envelope: ApiEnvelope<T> | null = null;
  try {
    envelope = (await response.json()) as ApiEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (!response.ok || envelope?.success === false) {
    if (response.status === 401 || response.status === 419) {
      resetCsrf();
    }
    if (
      envelope?.code === "RESTAURANT_NOT_FOUND" ||
      envelope?.code === "RESTAURANT_CONTEXT_REQUIRED"
    ) {
      if (typeof window !== "undefined") {
        window.localStorage.removeItem("suvakamana_restaurant_context_public_id");
      }
    }
    throw new ApiError(
      envelope?.code === "RESTAURANT_NOT_FOUND"
        ? "Restaurant context expired. Go to Admin → Menus and click Add item to Suvakamana again."
        : envelope?.code === "RESTAURANT_CONTEXT_REQUIRED"
          ? "No restaurant selected. Go to Admin → Menus and choose a restaurant first."
          : (envelope?.message ?? `Request failed (${response.status})`),
      response.status,
      envelope?.errors ?? null,
      envelope,
    );
  }

  return envelope as ApiEnvelope<T>;
}
