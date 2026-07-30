export type ApplicationStatus =
  | "draft"
  | "submitted"
  | "under_review"
  | "changes_requested"
  | "resubmitted"
  | "approved"
  | "rejected"
  | "withdrawn"
  | "expired";

export type BusinessType =
  | "sole_trader"
  | "partnership"
  | "company"
  | "trust"
  | "other";

export type ServiceType =
  | "delivery"
  | "pickup"
  | "dine_in"
  | "delivery_and_pickup"
  | "all";

export type DocumentType =
  | "business_registration"
  | "abn_document"
  | "food_business_licence"
  | "owner_identification"
  | "public_liability_insurance"
  | "bank_account_evidence"
  | "other";

export type AddressType = "registered" | "physical" | "billing";

export type AustralianState = "NSW" | "VIC" | "QLD" | "SA" | "WA" | "TAS" | "ACT" | "NT";

export type RestaurantAddress = {
  id?: number;
  address_type: AddressType;
  address_line_1: string;
  address_line_2?: string | null;
  suburb: string;
  state: AustralianState;
  postcode: string;
  country: string;
  is_primary?: boolean;
};

export type RestaurantDocument = {
  id: number;
  document_type: DocumentType;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  status: string;
  verification_notes?: string | null;
  expires_at?: string | null;
  verified_at?: string | null;
  created_at: string;
};

export type StatusHistoryEntry = {
  old_status: string | null;
  new_status: string;
  reason?: string | null;
  created_at: string;
};

export type ApplicationNote = {
  id: number;
  note: string;
  visibility: "internal" | "applicant_visible";
  created_at: string;
  author?: string | null;
};

export type CommissionAgreement = {
  id: number;
  commission_type: string;
  commission_rate?: string | number | null;
  fixed_fee_cents?: number | null;
  settlement_frequency: string;
  status: string;
  effective_from?: string | null;
  effective_until?: string | null;
  accepted_at?: string | null;
  terms_version?: string | null;
};

export type ApplicationApplicant = {
  id: number;
  name: string;
  email: string;
};

export type RestaurantApplication = {
  public_id: string;
  status: ApplicationStatus;
  version: number;
  legal_business_name?: string | null;
  trading_name?: string | null;
  business_type?: BusinessType | null;
  abn?: string | null;
  abn_raw?: string | null;
  business_registration_number?: string | null;
  business_email?: string | null;
  business_phone?: string | null;
  website_url?: string | null;
  description?: string | null;
  primary_contact_name?: string | null;
  primary_contact_email?: string | null;
  primary_contact_phone?: string | null;
  cuisine_summary?: string | null;
  service_type?: ServiceType | null;
  expected_monthly_orders?: string | null;
  current_delivery_method?: string | null;
  location_count?: number | null;
  referral_source?: string | null;
  submitted_at?: string | null;
  reviewed_at?: string | null;
  approved_at?: string | null;
  rejected_at?: string | null;
  rejection_category?: string | null;
  rejection_reason?: string | null;
  changes_requested_reason?: string | null;
  changes_requested_items?: string[] | null;
  terms_version?: string | null;
  terms_accepted_at?: string | null;
  restaurant_public_id?: string | null;
  applicant?: ApplicationApplicant | null;
  assigned_reviewer?: ApplicationApplicant | null;
  addresses?: RestaurantAddress[];
  documents?: RestaurantDocument[];
  status_history?: StatusHistoryEntry[];
  notes?: ApplicationNote[];
  commission_agreements?: CommissionAgreement[];
  updated_at?: string;
  created_at?: string;
};

export type ApplicationListMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type ApplicationWizardStep =
  | "contact"
  | "business"
  | "address"
  | "operations"
  | "documents"
  | "review";

export type UpdateApplicationPayload = {
  version?: number;
  legal_business_name?: string | null;
  trading_name?: string | null;
  business_type?: BusinessType | null;
  abn?: string | null;
  business_registration_number?: string | null;
  business_email?: string | null;
  business_phone?: string | null;
  website_url?: string | null;
  description?: string | null;
  primary_contact_name?: string | null;
  primary_contact_email?: string | null;
  primary_contact_phone?: string | null;
  cuisine_summary?: string | null;
  service_type?: ServiceType | null;
  expected_monthly_orders?: string | null;
  current_delivery_method?: string | null;
  location_count?: number | null;
  referral_source?: string | null;
  address?: {
    address_type?: AddressType;
    address_line_1: string;
    address_line_2?: string | null;
    suburb: string;
    state: AustralianState;
    postcode: string;
    country?: string;
  };
};

export type AdminListParams = {
  status?: string;
  search?: string;
  sort?: string;
  page?: number;
  per_page?: number;
  state?: string;
};
