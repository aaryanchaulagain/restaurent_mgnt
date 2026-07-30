import {
  apiFormData,
  apiGet,
  apiOrigin,
  apiRequest,
  type ApiEnvelope,
} from "@/lib/api/client";
import type {
  AdminListParams,
  ApplicationListMeta,
  ApplicationNote,
  CommissionAgreement,
  RestaurantApplication,
  RestaurantDocument,
  UpdateApplicationPayload,
} from "../types";

type ApplicationPayload = { application: RestaurantApplication };
type DocumentPayload = { document: RestaurantDocument };
type NotePayload = { note: ApplicationNote };
type AgreementPayload = { agreement: CommissionAgreement | null };

function buildQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") {
      search.set(key, String(value));
    }
  }
  const qs = search.toString();
  return qs ? `?${qs}` : "";
}

export const partnerApi = {
  createApplication() {
    return apiRequest<ApplicationPayload>("/api/v1/partner/applications", {
      method: "POST",
    });
  },

  getCurrentApplication() {
    return apiGet<ApplicationPayload>("/partner/applications/current");
  },

  getApplication(publicId: string) {
    return apiGet<ApplicationPayload>(`/partner/applications/${publicId}`);
  },

  updateApplication(publicId: string, payload: UpdateApplicationPayload) {
    return apiRequest<ApplicationPayload>(`/api/v1/partner/applications/${publicId}`, {
      method: "PATCH",
      body: payload,
    });
  },

  submitApplication(publicId: string) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/partner/applications/${publicId}/submit`,
      {
        method: "POST",
        body: { terms: true, confirm_accuracy: true },
      },
    );
  },

  resubmitApplication(publicId: string) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/partner/applications/${publicId}/resubmit`,
      { method: "POST" },
    );
  },

  withdrawApplication(publicId: string) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/partner/applications/${publicId}/withdraw`,
      { method: "POST" },
    );
  },

  uploadDocument(publicId: string, documentType: string, file: File) {
    const form = new FormData();
    form.append("document_type", documentType);
    form.append("document", file);
    return apiFormData<DocumentPayload>(
      `/api/v1/partner/applications/${publicId}/documents`,
      form,
    );
  },

  deleteDocument(publicId: string, documentId: number) {
    return apiRequest(
      `/api/v1/partner/applications/${publicId}/documents/${documentId}`,
      { method: "DELETE" },
    );
  },

  acceptCommission(publicId: string) {
    return apiRequest<AgreementPayload>(
      `/api/v1/partner/applications/${publicId}/commission-agreement/accept`,
      { method: "POST" },
    );
  },

  documentDownloadUrl(publicId: string, documentId: number, admin = false) {
    const base = admin
      ? `/api/v1/admin/restaurant-applications/${publicId}/documents/${documentId}/download`
      : `/api/v1/partner/applications/${publicId}/documents/${documentId}/download`;
    return `${apiOrigin}${base}`;
  },
};

export const adminPartnerApi = {
  listApplications(params: AdminListParams = {}) {
    const query = buildQuery({
      status: params.status,
      search: params.search,
      sort: params.sort,
      page: params.page,
      per_page: params.per_page,
      state: params.state,
    });
    return apiGet<{ applications: RestaurantApplication[] }>(
      `/admin/restaurant-applications${query}`,
    ) as Promise<
      ApiEnvelope<{ applications: RestaurantApplication[] }> & {
        meta: ApplicationListMeta | null;
      }
    >;
  },

  getApplication(publicId: string) {
    return apiGet<ApplicationPayload>(`/admin/restaurant-applications/${publicId}`);
  },

  startReview(publicId: string) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/start-review`,
      { method: "POST" },
    );
  },

  requestChanges(publicId: string, reason: string, items: string[]) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/request-changes`,
      { method: "POST", body: { reason, items } },
    );
  },

  approve(publicId: string, password: string) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/approve`,
      { method: "POST", body: { password } },
    );
  },

  reject(
    publicId: string,
    payload: { category: string; reason: string; internal_note?: string; password: string },
  ) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/reject`,
      { method: "POST", body: payload },
    );
  },

  assignReviewer(publicId: string, reviewerUserId: number) {
    return apiRequest<ApplicationPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/assign-reviewer`,
      { method: "POST", body: { reviewer_user_id: reviewerUserId } },
    );
  },

  verifyDocument(publicId: string, documentId: number, notes?: string) {
    return apiRequest<DocumentPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/documents/${documentId}/verify`,
      { method: "POST", body: { notes: notes ?? null } },
    );
  },

  rejectDocument(publicId: string, documentId: number, notes: string) {
    return apiRequest<DocumentPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/documents/${documentId}/reject`,
      { method: "POST", body: { notes } },
    );
  },

  addNote(publicId: string, note: string, visibility: "internal" | "applicant_visible") {
    return apiRequest<NotePayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/notes`,
      { method: "POST", body: { note, visibility } },
    );
  },

  getCommission(publicId: string) {
    return apiGet<AgreementPayload>(
      `/admin/restaurant-applications/${publicId}/commission-agreement`,
    );
  },

  saveCommission(
    publicId: string,
    payload: Record<string, unknown>,
    method: "POST" | "PATCH" = "POST",
  ) {
    return apiRequest<AgreementPayload>(
      `/api/v1/admin/restaurant-applications/${publicId}/commission-agreement`,
      { method, body: payload },
    );
  },
};
