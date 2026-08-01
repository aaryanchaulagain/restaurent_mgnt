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
  branchPublicId?: string | null;
  businessPublicId?: string | null;
};

function tenantContextHeaders(
  path: string,
  explicit?: {
    restaurantPublicId?: string | null;
    branchPublicId?: string | null;
    businessPublicId?: string | null;
  },
): Record<string, string> {
  const isRestaurantApi =
    path.includes("/api/v1/restaurant/") || path.includes("/restaurant/");
  if (!isRestaurantApi) return {};

  const headers: Record<string, string> = {};

  let branchId = explicit?.branchPublicId;
  if (branchId === undefined && typeof window !== "undefined") {
    branchId = window.localStorage.getItem("khana_branch_context_public_id");
  }
  if (branchId) {
    headers["X-Branch-Id"] = branchId;
  }

  let businessId = explicit?.businessPublicId;
  if (businessId === undefined && typeof window !== "undefined") {
    businessId = window.localStorage.getItem("khana_business_context_public_id");
  }
  if (businessId) {
    headers["X-Business-Id"] = businessId;
  }

  // Prefer branch; still send restaurant for legacy super-admin flows when no branch.
  let publicId = explicit?.restaurantPublicId;
  if (publicId === undefined && typeof window !== "undefined") {
    publicId = window.localStorage.getItem("suvakamana_restaurant_context_public_id");
  }
  if (publicId && !branchId) {
    headers["X-Restaurant-Id"] = publicId;
  } else if (publicId && branchId) {
    // Do not send mismatched restaurant with branch; server resolves restaurant.
  }

  return headers;
}

export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<ApiEnvelope<T>> {
  const {
    method = "GET",
    body,
    headers = {},
    skipCsrf = false,
    restaurantPublicId,
    branchPublicId,
    businessPublicId,
  } = options;

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
      ...tenantContextHeaders(path, { restaurantPublicId, branchPublicId, businessPublicId }),
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
      envelope?.code === "RESTAURANT_CONTEXT_REQUIRED" ||
      envelope?.code === "BRANCH_CONTEXT_REQUIRED" ||
      envelope?.code === "BRANCH_ACCESS_DENIED" ||
      envelope?.code === "BRANCH_NOT_FOUND"
    ) {
      if (typeof window !== "undefined") {
        window.localStorage.removeItem("suvakamana_restaurant_context_public_id");
        window.localStorage.removeItem("khana_branch_context_public_id");
      }
    }
    throw new ApiError(
      envelope?.code === "RESTAURANT_NOT_FOUND"
        ? "Restaurant context expired. Select a branch again."
        : envelope?.code === "RESTAURANT_CONTEXT_REQUIRED" ||
            envelope?.code === "BRANCH_CONTEXT_REQUIRED"
          ? "No branch selected. Choose a branch from the switcher."
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
      ...tenantContextHeaders(path),
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
      envelope?.code === "RESTAURANT_CONTEXT_REQUIRED" ||
      envelope?.code === "BRANCH_CONTEXT_REQUIRED" ||
      envelope?.code === "BRANCH_ACCESS_DENIED" ||
      envelope?.code === "BRANCH_NOT_FOUND"
    ) {
      if (typeof window !== "undefined") {
        window.localStorage.removeItem("suvakamana_restaurant_context_public_id");
        window.localStorage.removeItem("khana_branch_context_public_id");
      }
    }
    throw new ApiError(
      envelope?.code === "RESTAURANT_NOT_FOUND"
        ? "Restaurant context expired. Select a branch again."
        : envelope?.code === "RESTAURANT_CONTEXT_REQUIRED" ||
            envelope?.code === "BRANCH_CONTEXT_REQUIRED"
          ? "No branch selected. Choose a branch from the switcher."
          : (envelope?.message ?? `Request failed (${response.status})`),
      response.status,
      envelope?.errors ?? null,
      envelope,
    );
  }

  return envelope as ApiEnvelope<T>;
}
