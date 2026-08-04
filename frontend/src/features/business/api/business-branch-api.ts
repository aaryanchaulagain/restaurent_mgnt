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

export type BranchAuthorizationDto = {
  business: {
    public_id: string | null;
    business_type: string;
  };
  branch: {
    public_id: string;
    name: string;
  };
  role: string | null;
  permissions: string[];
};

export type BranchContextResponse = {
  can_aggregate: boolean;
  businesses: BusinessDto[];
  branches: BranchDto[];
  authorization?: BranchAuthorizationDto | null;
};

export type BranchListResponse = {
  business: BusinessDto;
  branches: BranchDto[];
  counts: Record<string, number>;
};

export type BranchInvitationDto = {
  public_id: string;
  email: string;
  full_name: string | null;
  phone: string | null;
  role: string;
  status: string;
  expires_at: string | null;
  accepted_at: string | null;
  revoked_at: string | null;
  resend_count: number;
  last_resent_at: string | null;
  created_at?: string | null;
};

export const INVITATION_ERROR_MESSAGES: Record<string, string> = {
  BRANCH_INVITATION_ACCESS_DENIED: "You are not allowed to manage invitations for this branch.",
  BRANCH_INVITATION_NOT_FOUND: "Invitation not found.",
  BRANCH_INVITATION_ALREADY_EXISTS: "A pending invitation already exists for this email and role.",
  BRANCH_INVITATION_ALREADY_ACCEPTED: "This invitation has already been accepted.",
  BRANCH_INVITATION_EXPIRED: "This invitation has expired.",
  BRANCH_INVITATION_REVOKED: "This invitation has been revoked.",
  BRANCH_INVITATION_TOKEN_INVALID: "This invitation link is invalid.",
  BRANCH_INVITATION_EMAIL_MISMATCH: "Sign in with the invited email address to continue.",
  BRANCH_INVITATION_BRANCH_UNAVAILABLE: "This branch is not available for staff onboarding.",
  BRANCH_INVITATION_ROLE_INVALID: "That invitation role is not allowed.",
  BRANCH_INVITATION_RESEND_LIMITED: "Please wait before resending this invitation.",
  BRANCH_STAFF_ASSIGNMENT_EXISTS: "This person is already assigned to the branch.",
  BRANCH_MANAGER_ACCESS_DENIED: "Branch manager access denied.",
  BRANCH_BUSINESS_MISMATCH: "Branch does not belong to this business.",
  BRANCH_INVITATION_REQUIRED: "New staff must be invited so they can create their own password.",
  MODULE_PERMISSION_DENIED: "You do not have permission for this action.",
  FINANCE_PERMISSION_DENIED: "You do not have finance permission for this branch.",
  ORDER_PERMISSION_DENIED: "You do not have order permission for this branch.",
  INVENTORY_PERMISSION_DENIED: "You do not have inventory permission for this branch.",
  STAFF_ROLE_PERMISSION_DENIED: "You cannot manage staff for this branch.",
  DELIVERY_PERMISSION_DENIED: "You do not have delivery permission for this branch.",
  BRANCH_ACCESS_DENIED: "Branch access denied.",
  BRANCH_CONTEXT_REQUIRED: "Select a branch to continue.",
  BRANCH_RESTAURANT_MISMATCH: "Branch and restaurant context do not match.",
};

export function invitationErrorMessage(err: { code?: string | null; message: string }): string {
  if (err.code && INVITATION_ERROR_MESSAGES[err.code]) {
    return INVITATION_ERROR_MESSAGES[err.code];
  }
  return err.message;
}

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
    apiRequest<{
      branch: BranchDto;
      restaurant_public_id: string;
      invitation?: BranchInvitationDto | null;
    }>(`/api/v1/businesses/${businessPublicId}/branches`, { method: "POST", body }),

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

  listBranchInvitations: (businessPublicId: string, branchPublicId: string) =>
    apiRequest<{ invitations: BranchInvitationDto[] }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/invitations`,
    ),

  createBranchInvitation: (
    businessPublicId: string,
    branchPublicId: string,
    body: Record<string, unknown>,
  ) =>
    apiRequest<{ invitation: BranchInvitationDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/invitations`,
      { method: "POST", body },
    ),

  resendBranchInvitation: (
    businessPublicId: string,
    branchPublicId: string,
    invitationPublicId: string,
  ) =>
    apiRequest<{ invitation: BranchInvitationDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/invitations/${invitationPublicId}/resend`,
      { method: "POST" },
    ),

  revokeBranchInvitation: (
    businessPublicId: string,
    branchPublicId: string,
    invitationPublicId: string,
  ) =>
    apiRequest<{ invitation: BranchInvitationDto }>(
      `/api/v1/businesses/${businessPublicId}/branches/${branchPublicId}/invitations/${invitationPublicId}/revoke`,
      { method: "POST" },
    ),

  previewBranchInvitation: (token: string) =>
    apiRequest<{
      existing_user: boolean;
      invitation: {
        public_id: string;
        email: string;
        full_name: string | null;
        role: string;
        status: string;
        expires_at: string | null;
        branch: { public_id: string; name: string };
        business: { public_id: string; name: string };
      };
    }>(`/api/v1/branch-invitations/${encodeURIComponent(token)}`),

  acceptBranchInvitation: (token: string, body: Record<string, unknown>) =>
    apiRequest<{
      user: unknown;
      branch: {
        public_id: string;
        name: string;
        business_public_id?: string;
        restaurant_public_id?: string | null;
      };
    }>(`/api/v1/branch-invitations/${encodeURIComponent(token)}/accept`, {
      method: "POST",
      body,
    }),
};
