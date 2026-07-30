"use client";

import Link from "next/link";
import { use, useState } from "react";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/forms";
import { Modal } from "@/components/ui/overlay";
import { useToast } from "@/components/ui/navigation";
import { adminNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";
import { ApplicationStatusBadge } from "@/features/partner/components/ApplicationStatusBadge";
import { ApplicationTimeline } from "@/features/partner/components/ApplicationTimeline";
import {
  COMMISSION_TYPES,
  REJECTION_CATEGORIES,
  SETTLEMENT_FREQUENCIES,
} from "@/features/partner/constants";
import {
  useAdminApplication,
  useAdminApplicationMutations,
} from "@/features/partner/hooks/use-partner-application";
import { partnerApi } from "@/features/partner/api/partner-api";
import {
  approveApplicationSchema,
  rejectApplicationSchema,
  zodFieldErrors,
} from "@/features/partner/schemas";
import { displayApplicationName, primaryAddress } from "@/features/partner/utils/status";
import type { RestaurantApplication } from "@/features/partner/types";

export default function AdminApplicationDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id: publicId } = use(params);
  const { data: app, isLoading } = useAdminApplication(publicId);

  if (isLoading || !app) {
    return (
      <AdminShell
        brand="Suvakamana"
        portalLabel="Super Admin"
        items={adminNav}
        title="Application"
        subtitle="Loading…"
      >
        <p className="text-sm text-[var(--text-secondary)]">Loading application…</p>
      </AdminShell>
    );
  }

  return <AdminApplicationDetailContent application={app} publicId={publicId} />;
}

function AdminApplicationDetailContent({
  application,
  publicId,
}: {
  application: RestaurantApplication;
  publicId: string;
}) {
  const mutations = useAdminApplicationMutations(publicId);
  const { push: toast } = useToast();

  const [changesReason, setChangesReason] = useState("");
  const [changesItems, setChangesItems] = useState("");
  const [noteText, setNoteText] = useState("");
  const [noteVisibility, setNoteVisibility] = useState<"internal" | "applicant_visible">(
    "internal",
  );
  const [docRejectNotes, setDocRejectNotes] = useState<Record<number, string>>({});

  const [approveOpen, setApproveOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [approvePassword, setApprovePassword] = useState("");
  const [rejectForm, setRejectForm] = useState({
    category: "",
    reason: "",
    internal_note: "",
    password: "",
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const [commission, setCommission] = useState({
    commission_type: "percentage",
    commission_rate: "12.5",
    fixed_fee_cents: "",
    settlement_frequency: "weekly",
    status: "offered",
  });

  const address = primaryAddress(application);

  async function handleStartReview() {
    try {
      await mutations.startReview.mutateAsync();
      toast({ title: "Review started", tone: "success" });
    } catch (error) {
      toast({
        title: "Unable to start review",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleRequestChanges() {
    const items = changesItems
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean);
    try {
      await mutations.requestChanges.mutateAsync({ reason: changesReason, items });
      toast({ title: "Changes requested", tone: "success" });
      setChangesReason("");
      setChangesItems("");
    } catch (error) {
      toast({
        title: "Unable to request changes",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleApprove() {
    const parsed = approveApplicationSchema.safeParse({ password: approvePassword });
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    try {
      await mutations.approve.mutateAsync(parsed.data.password);
      toast({
        title: "Application approved",
        description: displayApplicationName(application),
        tone: "success",
      });
      setApproveOpen(false);
      setApprovePassword("");
    } catch (error) {
      if (error instanceof ApiError && error.errors?.password) {
        setFieldErrors({ password: error.errors.password[0] });
      }
      toast({
        title: "Approval failed",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleReject() {
    const parsed = rejectApplicationSchema.safeParse(rejectForm);
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    try {
      await mutations.reject.mutateAsync(parsed.data);
      toast({ title: "Application rejected", tone: "success" });
      setRejectOpen(false);
      setRejectForm({ category: "", reason: "", internal_note: "", password: "" });
    } catch (error) {
      toast({
        title: "Rejection failed",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleAddNote() {
    if (!noteText.trim()) return;
    try {
      await mutations.addNote.mutateAsync({ note: noteText.trim(), visibility: noteVisibility });
      setNoteText("");
      toast({ title: "Note added", tone: "success" });
    } catch (error) {
      toast({
        title: "Unable to add note",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleSaveCommission() {
    try {
      await mutations.saveCommission.mutateAsync({
        commission_type: commission.commission_type,
        commission_rate: commission.commission_rate
          ? Number(commission.commission_rate)
          : null,
        fixed_fee_cents: commission.fixed_fee_cents
          ? Number(commission.fixed_fee_cents)
          : null,
        settlement_frequency: commission.settlement_frequency,
        status: commission.status,
      });
      toast({ title: "Commission offer saved", tone: "success" });
    } catch (error) {
      toast({
        title: "Unable to save commission",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  return (
    <AdminShell
      brand="Suvakamana"
      portalLabel="Super Admin"
      items={adminNav}
      title={displayApplicationName(application)}
      subtitle={`Application ${publicId}${application.submitted_at ? ` · Submitted ${new Date(application.submitted_at).toLocaleDateString()}` : ""}`}
      actions={
        <Link href="/admin/applications">
          <Button variant="outline">Back</Button>
        </Link>
      }
    >
      <div className="grid gap-6 lg:grid-cols-2">
        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-2xl">Application details</h2>
            <ApplicationStatusBadge status={application.status} />
          </div>
          <dl className="mt-4 space-y-2 text-sm">
            <Row label="Applicant" value={application.applicant?.name} />
            <Row label="Email" value={application.applicant?.email} />
            <Row label="ABN" value={application.abn} />
            <Row label="Cuisine" value={application.cuisine_summary} />
            <Row label="Contact" value={application.primary_contact_name} />
            <Row
              label="Address"
              value={
                address
                  ? `${address.address_line_1}, ${address.suburb} ${address.state} ${address.postcode}`
                  : undefined
              }
            />
          </dl>
          <div className="mt-4 flex flex-wrap gap-2">
            <Button size="sm" onClick={() => void handleStartReview()}>
              Start review
            </Button>
          </div>
        </section>

        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Decision</h2>
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => setApproveOpen(true)}>Approve</Button>
            <Button variant="destructive" onClick={() => setRejectOpen(true)}>
              Reject
            </Button>
          </div>
          <Field label="Request changes — reason">
            <Textarea
              value={changesReason}
              onChange={(e) => setChangesReason(e.target.value)}
              placeholder="Explain what the applicant should update"
            />
          </Field>
          <Field label="Requested items (one per line)" hint="Optional checklist for the applicant">
            <Textarea
              value={changesItems}
              onChange={(e) => setChangesItems(e.target.value)}
            />
          </Field>
          <Button
            variant="outline"
            onClick={() => void handleRequestChanges()}
            disabled={!changesReason.trim()}
          >
            Request changes
          </Button>
        </section>

        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 lg:col-span-2">
          <h2 className="text-2xl">Documents</h2>
          <ul className="mt-4 space-y-3">
            {(application.documents ?? []).map((doc) => (
              <li
                key={doc.id}
                className="flex flex-wrap items-start justify-between gap-3 rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3 text-sm"
              >
                <div>
                  <p className="font-medium">{doc.original_name}</p>
                  <p className="text-[var(--text-muted)]">
                    {doc.document_type.replace(/_/g, " ")} · {doc.status}
                  </p>
                  {doc.verification_notes ? (
                    <p className="mt-1 text-[var(--text-secondary)]">{doc.verification_notes}</p>
                  ) : null}
                </div>
                <div className="flex min-w-[12rem] flex-col gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                      window.open(
                        partnerApi.documentDownloadUrl(publicId, doc.id, true),
                        "_blank",
                        "noopener,noreferrer",
                      )
                    }
                  >
                    Download
                  </Button>
                  <Button
                    size="sm"
                    onClick={() =>
                      void mutations.verifyDocument.mutateAsync({ documentId: doc.id })
                    }
                  >
                    Verify
                  </Button>
                  <Input
                    placeholder="Rejection notes"
                    value={docRejectNotes[doc.id] ?? ""}
                    onChange={(e) =>
                      setDocRejectNotes((prev) => ({ ...prev, [doc.id]: e.target.value }))
                    }
                  />
                  <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => {
                      const notes = docRejectNotes[doc.id];
                      if (!notes?.trim()) return;
                      void mutations.rejectDocument.mutateAsync({
                        documentId: doc.id,
                        notes: notes.trim(),
                      });
                    }}
                  >
                    Reject document
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </section>

        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Commission offer</h2>
          <div className="mt-4 space-y-3">
            <Field label="Commission type">
              <Select
                value={commission.commission_type}
                onChange={(e) =>
                  setCommission((c) => ({ ...c, commission_type: e.target.value }))
                }
              >
                {COMMISSION_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Rate (%)">
              <Input
                value={commission.commission_rate}
                onChange={(e) =>
                  setCommission((c) => ({ ...c, commission_rate: e.target.value }))
                }
              />
            </Field>
            <Field label="Fixed fee (cents)">
              <Input
                value={commission.fixed_fee_cents}
                onChange={(e) =>
                  setCommission((c) => ({ ...c, fixed_fee_cents: e.target.value }))
                }
              />
            </Field>
            <Field label="Settlement">
              <Select
                value={commission.settlement_frequency}
                onChange={(e) =>
                  setCommission((c) => ({ ...c, settlement_frequency: e.target.value }))
                }
              >
                {SETTLEMENT_FREQUENCIES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Offer status">
              <Select
                value={commission.status}
                onChange={(e) => setCommission((c) => ({ ...c, status: e.target.value }))}
              >
                <option value="draft">Draft</option>
                <option value="offered">Offered</option>
              </Select>
            </Field>
            <Button onClick={() => void handleSaveCommission()}>Save commission offer</Button>
          </div>
        </section>

        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Notes</h2>
          <Field label="Visibility">
            <Select
              value={noteVisibility}
              onChange={(e) =>
                setNoteVisibility(e.target.value as "internal" | "applicant_visible")
              }
            >
              <option value="internal">Internal only</option>
              <option value="applicant_visible">Visible to applicant</option>
            </Select>
          </Field>
          <Textarea
            className="mt-3"
            placeholder="Add a review note"
            value={noteText}
            onChange={(e) => setNoteText(e.target.value)}
          />
          <Button className="mt-3" variant="outline" onClick={() => void handleAddNote()}>
            Add note
          </Button>
          <ul className="mt-4 space-y-2 text-sm">
            {(application.notes ?? []).map((note) => (
              <li
                key={note.id}
                className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3"
              >
                <p className="text-xs text-[var(--text-muted)]">
                  {note.visibility === "internal" ? "Internal" : "Applicant visible"} ·{" "}
                  {note.author ?? "Admin"} · {new Date(note.created_at).toLocaleString()}
                </p>
                <p className="mt-1">{note.note}</p>
              </li>
            ))}
          </ul>
        </section>

        <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 lg:col-span-2">
          <h2 className="text-2xl">Timeline</h2>
          <div className="mt-4">
            <ApplicationTimeline history={application.status_history ?? []} />
          </div>
        </section>
      </div>

      <Modal open={approveOpen} onClose={() => setApproveOpen(false)} title="Confirm approval">
        <p className="text-sm text-[var(--text-secondary)]">
          Re-enter your password to approve this restaurant application.
        </p>
        <Field label="Password" className="mt-4" error={fieldErrors.password}>
          <Input
            type="password"
            value={approvePassword}
            onChange={(e) => setApprovePassword(e.target.value)}
          />
        </Field>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setApproveOpen(false)}>
            Cancel
          </Button>
          <Button onClick={() => void handleApprove()}>Approve application</Button>
        </div>
      </Modal>

      <Modal open={rejectOpen} onClose={() => setRejectOpen(false)} title="Reject application">
        <div className="space-y-3">
          <Field label="Category" error={fieldErrors.category}>
            <Select
              value={rejectForm.category}
              onChange={(e) => setRejectForm((f) => ({ ...f, category: e.target.value }))}
            >
              <option value="">Select category</option>
              {REJECTION_CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Reason for applicant" error={fieldErrors.reason}>
            <Textarea
              value={rejectForm.reason}
              onChange={(e) => setRejectForm((f) => ({ ...f, reason: e.target.value }))}
            />
          </Field>
          <Field label="Internal note (optional)">
            <Textarea
              value={rejectForm.internal_note}
              onChange={(e) => setRejectForm((f) => ({ ...f, internal_note: e.target.value }))}
            />
          </Field>
          <Field label="Password" error={fieldErrors.password}>
            <Input
              type="password"
              value={rejectForm.password}
              onChange={(e) => setRejectForm((f) => ({ ...f, password: e.target.value }))}
            />
          </Field>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setRejectOpen(false)}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={() => void handleReject()}>
            Reject application
          </Button>
        </div>
      </Modal>
    </AdminShell>
  );
}

function Row({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-[var(--text-muted)]">{label}</dt>
      <dd>{value ?? "—"}</dd>
    </div>
  );
}
