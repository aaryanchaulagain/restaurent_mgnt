"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { ApiError } from "@/lib/api/client";
import { ApplicationProgress } from "./ApplicationProgress";
import { ApplicationReview } from "./ApplicationReview";
import { ApplicationStepLayout } from "./ApplicationStepLayout";
import { BusinessDetailsForm, type BusinessDetailsValues } from "./BusinessDetailsForm";
import { DocumentUploader } from "./DocumentUploader";
import { OperationsForm, type OperationsFormValues } from "./OperationsForm";
import {
  emptyAddressValues,
  RestaurantAddressForm,
  type AddressFormValues,
} from "./RestaurantAddressForm";
import { TermsAcceptance } from "./TermsAcceptance";
import { DEFAULT_COUNTRY } from "../constants";
import {
  useCreatePartnerApplication,
  useCurrentPartnerApplication,
  useDeletePartnerDocument,
  usePartnerApplication,
  useSubmitPartnerApplication,
  useUpdatePartnerApplication,
  useUploadPartnerDocument,
} from "../hooks/use-partner-application";
import {
  addressStepSchema,
  businessStepSchema,
  contactStepSchema,
  operationsStepSchema,
  submitApplicationSchema,
  zodFieldErrors,
} from "../schemas";
import type { ApplicationWizardStep, RestaurantApplication } from "../types";
import { primaryAddress } from "../utils/status";

type ContactValues = {
  primary_contact_name: string;
  primary_contact_email: string;
  primary_contact_phone: string;
  business_email: string;
  business_phone: string;
};

function appToContact(app: RestaurantApplication): ContactValues {
  return {
    primary_contact_name: app.primary_contact_name ?? "",
    primary_contact_email: app.primary_contact_email ?? "",
    primary_contact_phone: app.primary_contact_phone ?? "",
    business_email: app.business_email ?? "",
    business_phone: app.business_phone ?? "",
  };
}

function appToBusiness(app: RestaurantApplication): BusinessDetailsValues {
  return {
    legal_business_name: app.legal_business_name ?? "",
    trading_name: app.trading_name ?? "",
    business_type: app.business_type ?? "",
    abn: app.abn_raw ?? app.abn?.replace(/\D/g, "") ?? "",
    business_registration_number: app.business_registration_number ?? "",
    description: app.description ?? "",
    cuisine_summary: app.cuisine_summary ?? "",
    website_url: app.website_url ?? "",
  };
}

function appToAddress(app: RestaurantApplication): AddressFormValues {
  const addr = primaryAddress(app);
  if (!addr) return emptyAddressValues();
  return {
    address_line_1: addr.address_line_1,
    address_line_2: addr.address_line_2 ?? "",
    suburb: addr.suburb,
    state: addr.state,
    postcode: addr.postcode,
    country: addr.country || DEFAULT_COUNTRY,
  };
}

function appToOperations(app: RestaurantApplication): OperationsFormValues {
  return {
    service_type: app.service_type ?? "",
    expected_monthly_orders: app.expected_monthly_orders ?? "",
    current_delivery_method: app.current_delivery_method ?? "",
    location_count: app.location_count != null ? String(app.location_count) : "1",
    referral_source: app.referral_source ?? "",
  };
}

export function PartnerApplyWizard({ initialPublicId }: { initialPublicId?: string | null }) {
  const router = useRouter();
  const { push: toast } = useToast();
  const [createdPublicId, setCreatedPublicId] = useState<string | null>(null);
  const { data: currentApplication, isLoading: currentLoading } =
    useCurrentPartnerApplication();
  const publicId =
    initialPublicId ?? currentApplication?.public_id ?? createdPublicId ?? null;
  const [step, setStep] = useState<ApplicationWizardStep>("contact");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const createMutation = useCreatePartnerApplication();
  const { data: application, isLoading, refetch } = usePartnerApplication(publicId);
  const updateMutation = useUpdatePartnerApplication(publicId ?? "");
  const submitMutation = useSubmitPartnerApplication(publicId ?? "");
  const uploadMutation = useUploadPartnerDocument(publicId ?? "");
  const deleteMutation = useDeletePartnerDocument(publicId ?? "");

  const [contact, setContact] = useState<ContactValues>({
    primary_contact_name: "",
    primary_contact_email: "",
    primary_contact_phone: "",
    business_email: "",
    business_phone: "",
  });
  const [business, setBusiness] = useState<BusinessDetailsValues>({
    legal_business_name: "",
    trading_name: "",
    business_type: "",
    abn: "",
    business_registration_number: "",
    description: "",
    cuisine_summary: "",
    website_url: "",
  });
  const [address, setAddress] = useState<AddressFormValues>(emptyAddressValues());
  const [operations, setOperations] = useState<OperationsFormValues>({
    service_type: "",
    expected_monthly_orders: "",
    current_delivery_method: "",
    location_count: "1",
    referral_source: "",
  });
  const [terms, setTerms] = useState(false);
  const [confirmAccuracy, setConfirmAccuracy] = useState(false);

  const hydratedRef = useRef(false);

  useEffect(() => {
    if (!application || hydratedRef.current) return;
    setContact(appToContact(application));
    setBusiness(appToBusiness(application));
    setAddress(appToAddress(application));
    setOperations(appToOperations(application));
    hydratedRef.current = true;
  }, [application]);

  useEffect(() => {
    if (publicId || currentLoading || createMutation.isPending || createMutation.isSuccess) {
      return;
    }
    createMutation.mutate(undefined, {
      onSuccess: (res) => {
        setCreatedPublicId(res.data.application.public_id);
      },
      onError: (err) => {
        setFormError(err instanceof ApiError ? err.message : "Unable to start application.");
      },
    });
  }, [publicId, currentLoading, createMutation]);

  useEffect(() => {
    if (!application) return;
    if (application.status !== "draft" && application.status !== "changes_requested") {
      router.replace(`/partner/applications/${application.public_id}`);
    }
  }, [application, router]);

  const saveDraft = useCallback(
    async (payload: Record<string, unknown>) => {
      if (!publicId || !application) return;
      await updateMutation.mutateAsync({
        version: application.version,
        ...payload,
      });
    },
    [publicId, application, updateMutation],
  );

  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const scheduleAutosave = useCallback(
    (payload: Record<string, unknown>) => {
      if (!publicId || !application) return;
      if (debounceRef.current) clearTimeout(debounceRef.current);
      debounceRef.current = setTimeout(() => {
        void saveDraft(payload).catch(() => {
          /* silent autosave */
        });
      }, 1200);
    },
    [publicId, application, saveDraft],
  );

  const saving = updateMutation.isPending;

  async function persistStep(payload: Record<string, unknown>) {
    setFormError(null);
    setFieldErrors({});
    try {
      await saveDraft(payload);
    } catch (error) {
      if (error instanceof ApiError && error.errors) {
        const next: Record<string, string> = {};
        for (const [key, messages] of Object.entries(error.errors)) {
          next[key] = messages[0] ?? error.message;
        }
        setFieldErrors(next);
      }
      setFormError(error instanceof ApiError ? error.message : "Unable to save draft.");
      throw error;
    }
  }

  async function goContactNext() {
    const parsed = contactStepSchema.safeParse(contact);
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    await persistStep(parsed.data);
    setStep("business");
  }

  async function goBusinessNext() {
    const parsed = businessStepSchema.safeParse(business);
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    const data = parsed.data;
    await persistStep({
      ...data,
      website_url: data.website_url || null,
      business_registration_number: data.business_registration_number || null,
    });
    setStep("address");
  }

  async function goAddressNext() {
    const parsed = addressStepSchema.safeParse(address);
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    await persistStep({
      address: {
        address_type: "physical",
        address_line_1: parsed.data.address_line_1,
        address_line_2: parsed.data.address_line_2 || null,
        suburb: parsed.data.suburb,
        state: parsed.data.state,
        postcode: parsed.data.postcode,
        country: parsed.data.country || DEFAULT_COUNTRY,
      },
    });
    setStep("operations");
  }

  async function goOperationsNext() {
    const parsed = operationsStepSchema.safeParse(operations);
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    await persistStep({
      service_type: parsed.data.service_type,
      expected_monthly_orders: parsed.data.expected_monthly_orders || null,
      current_delivery_method: parsed.data.current_delivery_method || null,
      location_count: parsed.data.location_count ?? 1,
      referral_source: parsed.data.referral_source || null,
    });
    setStep("documents");
  }

  async function goDocumentsNext() {
    await refetch();
    setStep("review");
  }

  async function handleSubmit() {
    const parsed = submitApplicationSchema.safeParse({ terms, confirm_accuracy: confirmAccuracy });
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    if (!publicId) return;
    try {
      await submitMutation.mutateAsync();
      toast({
        title: "Application submitted",
        description: "We will review your application within two business days.",
        tone: "success",
      });
      router.push(`/partner/applications/${publicId}`);
    } catch (error) {
      setFormError(error instanceof ApiError ? error.message : "Unable to submit.");
    }
  }

  const documents = useMemo(() => application?.documents ?? [], [application]);

  if (isLoading || currentLoading || !publicId || !application) {
    return (
      <p className="text-sm text-[var(--text-secondary)]" aria-busy="true">
        Preparing your application…
      </p>
    );
  }

  return (
    <div className="mt-8 space-y-6 rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
      <ApplicationProgress currentStep={step} />
      {formError ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {formError}
        </p>
      ) : null}

      {step === "contact" ? (
        <ApplicationStepLayout
          title="Owner & contact"
          description="How we reach you about this application."
          onNext={() => void goContactNext()}
          nextLabel="Continue to business details"
          saving={saving}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Primary contact name"
              htmlFor="primary_contact_name"
              error={fieldErrors.primary_contact_name}
              className="sm:col-span-2"
            >
              <Input
                id="primary_contact_name"
                value={contact.primary_contact_name}
                onChange={(e) => {
                  const next = { ...contact, primary_contact_name: e.target.value };
                  setContact(next);
                  scheduleAutosave(next);
                }}
              />
            </Field>
            <Field label="Contact email" htmlFor="primary_contact_email" error={fieldErrors.primary_contact_email}>
              <Input
                id="primary_contact_email"
                type="email"
                value={contact.primary_contact_email}
                onChange={(e) => {
                  const next = { ...contact, primary_contact_email: e.target.value };
                  setContact(next);
                  scheduleAutosave(next);
                }}
              />
            </Field>
            <Field label="Contact phone" htmlFor="primary_contact_phone" error={fieldErrors.primary_contact_phone}>
              <Input
                id="primary_contact_phone"
                value={contact.primary_contact_phone}
                onChange={(e) => {
                  const next = { ...contact, primary_contact_phone: e.target.value };
                  setContact(next);
                  scheduleAutosave(next);
                }}
              />
            </Field>
            <Field label="Business email" htmlFor="business_email" error={fieldErrors.business_email}>
              <Input
                id="business_email"
                type="email"
                value={contact.business_email}
                onChange={(e) => {
                  const next = { ...contact, business_email: e.target.value };
                  setContact(next);
                  scheduleAutosave(next);
                }}
              />
            </Field>
            <Field label="Business phone" htmlFor="business_phone" error={fieldErrors.business_phone}>
              <Input
                id="business_phone"
                value={contact.business_phone}
                onChange={(e) => {
                  const next = { ...contact, business_phone: e.target.value };
                  setContact(next);
                  scheduleAutosave(next);
                }}
              />
            </Field>
          </div>
        </ApplicationStepLayout>
      ) : null}

      {step === "business" ? (
        <ApplicationStepLayout
          title="Business details"
          onBack={() => setStep("contact")}
          onNext={() => void goBusinessNext()}
          saving={saving}
        >
          <BusinessDetailsForm
            values={business}
            errors={fieldErrors}
            onChange={(patch) => {
              const next = { ...business, ...patch };
              setBusiness(next);
              scheduleAutosave({
                ...next,
                abn: next.abn.replace(/\D/g, ""),
              });
            }}
          />
        </ApplicationStepLayout>
      ) : null}

      {step === "address" ? (
        <ApplicationStepLayout
          title="Restaurant address"
          onBack={() => setStep("business")}
          onNext={() => void goAddressNext()}
          saving={saving}
        >
          <RestaurantAddressForm
            values={address}
            errors={fieldErrors}
            onChange={(patch) => {
              const next = { ...address, ...patch };
              setAddress(next);
            }}
          />
        </ApplicationStepLayout>
      ) : null}

      {step === "operations" ? (
        <ApplicationStepLayout
          title="Operations"
          onBack={() => setStep("address")}
          onNext={() => void goOperationsNext()}
          saving={saving}
        >
          <OperationsForm
            values={operations}
            errors={fieldErrors}
            onChange={(patch) => setOperations({ ...operations, ...patch })}
          />
        </ApplicationStepLayout>
      ) : null}

      {step === "documents" ? (
        <ApplicationStepLayout
          title="Documents"
          description="Upload required compliance documents."
          onBack={() => setStep("operations")}
          onNext={() => void goDocumentsNext()}
          nextLabel="Continue to review"
        >
          <DocumentUploader
            publicId={publicId}
            documents={documents}
            onUpload={async (documentType, file) => {
              await uploadMutation.mutateAsync({ documentType, file });
            }}
            onDelete={async (documentId) => {
              await deleteMutation.mutateAsync(documentId);
            }}
          />
        </ApplicationStepLayout>
      ) : null}

      {step === "review" ? (
        <ApplicationStepLayout
          title="Review & submit"
          onBack={() => setStep("documents")}
          onNext={() => void handleSubmit()}
          nextLabel={submitMutation.isPending ? "Submitting…" : "Submit application"}
          nextDisabled={submitMutation.isPending}
        >
          <ApplicationReview application={application} />
          <TermsAcceptance
            terms={terms}
            confirmAccuracy={confirmAccuracy}
            onTermsChange={setTerms}
            onConfirmChange={setConfirmAccuracy}
            errors={fieldErrors}
          />
          <Button type="button" variant="outline" disabled={saving} onClick={() => void saveDraft({})}>
            Save draft
          </Button>
        </ApplicationStepLayout>
      ) : null}
    </div>
  );
}
