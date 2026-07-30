"use client";

import Link from "next/link";
import { use } from "react";
import { AuthGuard } from "@/features/auth/guards/route-guard";
import { Breadcrumbs } from "@/components/ui/navigation";
import { Button } from "@/components/ui/button";
import { ApplicationStatusBadge } from "@/features/partner/components/ApplicationStatusBadge";
import { ApplicationTimeline } from "@/features/partner/components/ApplicationTimeline";
import { CommissionOfferCard } from "@/features/partner/components/CommissionOfferCard";
import { RequestedChangesPanel } from "@/features/partner/components/RequestedChangesPanel";
import {
  useAcceptCommission,
  usePartnerApplication,
  useResubmitPartnerApplication,
  useWithdrawPartnerApplication,
} from "@/features/partner/hooks/use-partner-application";
import {
  canResubmitApplication,
  canWithdrawApplication,
  displayApplicationName,
  isEditableByApplicant,
  primaryAddress,
} from "@/features/partner/utils/status";
import { useToast } from "@/components/ui/navigation";
import { ApiError } from "@/lib/api/client";

function ApplicationDashboard({ publicId }: { publicId: string }) {
  const { data: application, isLoading } = usePartnerApplication(publicId);
  const resubmit = useResubmitPartnerApplication(publicId);
  const withdraw = useWithdrawPartnerApplication(publicId);
  const acceptCommission = useAcceptCommission(publicId);
  const { push: toast } = useToast();

  if (isLoading || !application) {
    return (
      <p className="text-sm text-[var(--text-secondary)]" aria-busy="true">
        Loading application…
      </p>
    );
  }

  const address = primaryAddress(application);
  const latestCommission = application.commission_agreements?.[0] ?? null;

  async function handleResubmit() {
    try {
      await resubmit.mutateAsync();
      toast({ title: "Application resubmitted", tone: "success" });
    } catch (error) {
      toast({
        title: "Unable to resubmit",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  async function handleWithdraw() {
    try {
      await withdraw.mutateAsync();
      toast({ title: "Application withdrawn", tone: "success" });
    } catch (error) {
      toast({
        title: "Unable to withdraw",
        description: error instanceof ApiError ? error.message : undefined,
        tone: "error",
      });
    }
  }

  return (
    <div className="mt-8 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <ApplicationStatusBadge status={application.status} />
        <div className="flex flex-wrap gap-2">
          {isEditableByApplicant(application.status) ? (
            <Link href="/partner/apply">
              <Button variant="outline">Edit application</Button>
            </Link>
          ) : null}
          {canResubmitApplication(application) ? (
            <Button onClick={() => void handleResubmit()} disabled={resubmit.isPending}>
              Resubmit
            </Button>
          ) : null}
          {canWithdrawApplication(application) ? (
            <Button
              variant="destructive"
              onClick={() => void handleWithdraw()}
              disabled={withdraw.isPending}
            >
              Withdraw
            </Button>
          ) : null}
        </div>
      </div>

      <RequestedChangesPanel application={application} />

      <section className="rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
        <h2 className="text-2xl">{displayApplicationName(application)}</h2>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">
          Reference {application.public_id}
          {application.submitted_at
            ? ` · Submitted ${new Date(application.submitted_at).toLocaleString()}`
            : ""}
        </p>
        <dl className="mt-6 grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-[var(--text-muted)]">Contact</dt>
            <dd>{application.primary_contact_name}</dd>
          </div>
          <div>
            <dt className="text-[var(--text-muted)]">Cuisine</dt>
            <dd>{application.cuisine_summary ?? "—"}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="text-[var(--text-muted)]">Address</dt>
            <dd>
              {address
                ? `${address.address_line_1}, ${address.suburb} ${address.state} ${address.postcode}`
                : "—"}
            </dd>
          </div>
        </dl>
      </section>

      <section className="rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
        <h2 className="text-2xl">Commission</h2>
        <div className="mt-4">
          <CommissionOfferCard
            agreement={latestCommission}
            onAccept={
              latestCommission?.status === "offered"
                ? () => void acceptCommission.mutateAsync()
                : undefined
            }
            accepting={acceptCommission.isPending}
          />
        </div>
      </section>

      {application.rejection_reason ? (
        <section className="rounded-[var(--radius-lg)] border border-[var(--color-error)]/30 bg-[var(--color-error)]/5 p-5">
          <h2 className="text-xl">Application outcome</h2>
          <p className="mt-2 text-sm">{application.rejection_reason}</p>
        </section>
      ) : null}

      {application.notes?.length ? (
        <section className="rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
          <h2 className="text-2xl">Messages from our team</h2>
          <ul className="mt-4 space-y-3">
            {application.notes.map((note) => (
              <li
                key={note.id}
                className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] p-3 text-sm"
              >
                <p>{note.note}</p>
                <p className="mt-2 text-xs text-[var(--text-muted)]">
                  {note.author ?? "Suvakamana"} · {new Date(note.created_at).toLocaleString()}
                </p>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-md)]">
        <h2 className="text-2xl">Timeline</h2>
        <div className="mt-4">
          <ApplicationTimeline history={application.status_history ?? []} />
        </div>
      </section>
    </div>
  );
}

export default function PartnerApplicationStatusPage({
  params,
}: {
  params: Promise<{ publicId: string }>;
}) {
  const { publicId } = use(params);

  return (
    <AuthGuard requireMfaForAdmin={false}>
      <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <Breadcrumbs
          items={[
            { label: "Home", href: "/" },
            { label: "Partner application", href: "/partner/application" },
            { label: "Status" },
          ]}
        />
        <h1 className="mt-4 text-4xl">Application status</h1>
        <ApplicationDashboard publicId={publicId} />
      </main>
    </AuthGuard>
  );
}
