import { apiRequest } from "@/lib/api/client";

export type BranchDto = {
  id: number;
  public_id: string;
  business_id: number;
  business_public_id?: string;
  business_name?: string;
  name: string;
  code: string | null;
  email: string | null;
  phone: string | null;
  status: string;
  status_label: string;
  timezone: string | null;
  address_line: string | null;
  city: string | null;
  state: string | null;
  postcode: string | null;
  country: string | null;
  accepting_orders: boolean;
  is_default: boolean;
  is_operational: boolean;
  allows_configuration: boolean;
  restaurant_public_id?: string | null;
  staff_count?: number;
  manager_count?: number;
  created_at?: string | null;
};

export type BusinessDto = {
  id: number;
  public_id: string;
  name: string;
  slug: string;
  business_type: string;
  status: string;
  branches_count?: number;
};

export type BranchContextResponse = {
  can_aggregate: boolean;
  businesses: BusinessDto[];
  branches: BranchDto[];
};

export type BranchListResponse = {
  business: BusinessDto;
  branches: BranchDto[];
  counts: Record<string, number>;
};

export const businessBranchApi = {
  context: () =>
    apiRequest<BranchContextResponse>("/api/v1/businesses/context"),

  listBranches: (businessPublicId: string) =>
    apiRequest<BranchListResponse>(`/api/v1/businesses/${businessPublicId}/branches`),

  showBranch: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{ branch: BranchDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}`,
    ),

  createBranch: (businessPublicId: string, body: Record<string, unknown>) =>
    apiRequest<{ branch: BranchDto; restaurant_public_id: string }>(
      `/api/v1/businesses/${businessPublicId}/branches`,
      { method: "POST", body },
    ),

  updateBranch: (
    businessPublicId: string,
    branchPublicId: string,
    body: Record<string, unknown>,
  ) =>
    apiRequest<{ branch: BranchDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}`,
      { method: "PATCH", body },
    ),

  pause: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{ branch: BranchDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/pause`,
      { method: "POST" },
    ),

  activate: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{ branch: BranchDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/activate`,
      { method: "POST" },
    ),

  deactivate: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{ branch: BranchDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/deactivate`,
      { method: "POST" },
    ),

  listBranchUsers: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{
      users: { user_id: number; email: string; name: string; role: string }[];
    }>(`/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/users`),

  assignBranchUser: (
    businessPublicId: string,
    branchPublicId: string,
    body: Record<string, unknown>,
  ) =>
    apiRequest<{
      user: { id: number; email: string; name: string; role: string };
      temporary_password: string | null;
    }>(`/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/users`, {
      method: "POST",
      body,
    }),

  removeBranchUser: (
    businessPublicId: string,
    branchPublicId: string,
    userId: number,
  ) =>
    apiRequest(`/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/users/${userId}`, {
      method: "DELETE",
    }),
};
