"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { ApiError } from "@/lib/api/client";
import { businessBranchApi, invitationErrorMessage } from "@/features/business/api/business-branch-api";
import { setBranchDashboardContext } from "@/features/business/lib/branch-context";

export default function BranchSettingsPage() {
  const { items: navItems, portalLabel: shellPortalLabel } = useRestaurantShell();
  const params = useParams<{ business: string; branch: string }>();
  const businessPublicId = params.business;
  const branchPublicId = params.branch;
  const { push } = useToast();
  const qc = useQueryClient();

  const branchQuery = useQuery({
    queryKey: ["branch", businessPublicId, branchPublicId],
    queryFn: async () =>
      (await businessBranchApi.showBranch(businessPublicId, branchPublicId)).data.branch,
  });

  const staffQuery = useQuery({
    queryKey: ["branch-staff", businessPublicId, branchPublicId],
    queryFn: async () =>
      (await businessBranchApi.listBranchUsers(businessPublicId, branchPublicId)).data.users,
  });

  const invitationsQuery = useQuery({
    queryKey: ["branch-invitations", businessPublicId, branchPublicId],
    queryFn: async () =>
      (await businessBranchApi.listBranchInvitations(businessPublicId, branchPublicId)).data
        .invitations,
  });

  const branch = branchQuery.data;

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [addressLine, setAddressLine] = useState("");
  const [city, setCity] = useState("");

  useEffect(() => {
    if (!branch) return;
    setName(branch.name);
    setEmail(branch.email ?? "");
    setPhone(branch.phone ?? "");
    setAddressLine(branch.address_line ?? "");
    setCity(branch.city ?? "");
  }, [branch]);

  const [staffEmail, setStaffEmail] = useState("");
  const [staffFirst, setStaffFirst] = useState("");
  const [staffLast, setStaffLast] = useState("");
  const [staffRole, setStaffRole] = useState("branch_manager");

  const save = useMutation({
    mutationFn: () =>
      businessBranchApi.updateBranch(businessPublicId, branchPublicId, {
        name,
        email: email || null,
        phone: phone || null,
        address_line: addressLine || null,
        city: city || null,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["branch", businessPublicId, branchPublicId] });
      qc.invalidateQueries({ queryKey: ["business-branch-context"] });
      push({ title: "Branch updated", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Update failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const statusMutation = useMutation({
    mutationFn: (action: "pause" | "activate" | "deactivate") =>
      businessBranchApi[action](businessPublicId, branchPublicId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["branch", businessPublicId, branchPublicId] });
      qc.invalidateQueries({ queryKey: ["business-branch-context"] });
      push({ title: "Status updated", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Status change failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const inviteStaff = useMutation({
    mutationFn: () =>
      businessBranchApi.createBranchInvitation(businessPublicId, branchPublicId, {
        email: staffEmail,
        full_name: [staffFirst, staffLast].filter(Boolean).join(" ") || undefined,
        role: staffRole,
      }),
    onSuccess: () => {
      setStaffEmail("");
      setStaffFirst("");
      setStaffLast("");
      qc.invalidateQueries({ queryKey: ["branch-invitations", businessPublicId, branchPublicId] });
      push({
        title: "Invitation sent",
        description: "They will create their own password from the secure email link.",
        tone: "success",
      });
    },
    onError: (err: unknown) => {
      push({
        title: "Invitation failed",
        description:
          err instanceof ApiError ? invitationErrorMessage(err) : "Request failed",
        tone: "error",
      });
    },
  });

  const resendInvite = useMutation({
    mutationFn: (invitationPublicId: string) =>
      businessBranchApi.resendBranchInvitation(
        businessPublicId,
        branchPublicId,
        invitationPublicId,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["branch-invitations", businessPublicId, branchPublicId] });
      push({ title: "Invitation resent", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Resend failed",
        description:
          err instanceof ApiError ? invitationErrorMessage(err) : "Request failed",
        tone: "error",
      });
    },
  });

  const revokeInvite = useMutation({
    mutationFn: (invitationPublicId: string) =>
      businessBranchApi.revokeBranchInvitation(
        businessPublicId,
        branchPublicId,
        invitationPublicId,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["branch-invitations", businessPublicId, branchPublicId] });
      push({ title: "Invitation revoked", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Revoke failed",
        description:
          err instanceof ApiError ? invitationErrorMessage(err) : "Request failed",
        tone: "error",
      });
    },
  });

  const removeStaff = useMutation({
    mutationFn: (userId: number) =>
      businessBranchApi.removeBranchUser(businessPublicId, branchPublicId, userId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["branch-staff", businessPublicId, branchPublicId] });
      push({ title: "Staff removed", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Remove failed",
        description:
          err instanceof ApiError ? invitationErrorMessage(err) : "Request failed",
        tone: "error",
      });
    },
  });

  return (
    <AdminShell
      brand={branch?.business_name ?? "Business"}
      portalLabel={shellPortalLabel}
      items={navItems}
      title={branch?.name ?? "Branch settings"}
      subtitle="General, status, and staff for this location"
      actions={
        branch ? (
          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              setBranchDashboardContext({
                businessPublicId,
                branchPublicId: branch.public_id,
                restaurantPublicId: branch.restaurant_public_id ?? null,
                aggregate: false,
              })
            }
          >
            Use this branch
          </Button>
        ) : null
      }
    >
      {branchQuery.isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {branch ? (
        <div className="grid gap-6 xl:grid-cols-2">
          <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
            <div className="flex items-center gap-2">
              <h2 className="text-xl">General</h2>
              <Badge>{branch.status_label}</Badge>
            </div>
            <p className="text-sm text-black/55">
              Code <strong>{branch.code}</strong>
              {branch.restaurant_public_id
                ? ` · Linked restaurant ${branch.restaurant_public_id.slice(0, 8)}…`
                : " · No linked restaurant"}
            </p>
            <Field label="Name">
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Email">
                <Input value={email} onChange={(e) => setEmail(e.target.value)} />
              </Field>
              <Field label="Phone">
                <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
              </Field>
            </div>
            <Field label="Address">
              <Input value={addressLine} onChange={(e) => setAddressLine(e.target.value)} />
            </Field>
            <Field label="City">
              <Input value={city} onChange={(e) => setCity(e.target.value)} />
            </Field>
            <Button onClick={() => save.mutate()} disabled={save.isPending}>
              Save changes
            </Button>
            <div className="flex flex-wrap gap-2 border-t border-black/5 pt-4">
              <Button
                size="sm"
                variant="outline"
                disabled={statusMutation.isPending || branch.status === "suspended"}
                onClick={() => statusMutation.mutate("activate")}
              >
                Activate
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={statusMutation.isPending || branch.status === "suspended"}
                onClick={() => statusMutation.mutate("pause")}
              >
                Pause
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={statusMutation.isPending || branch.status === "suspended"}
                onClick={() => statusMutation.mutate("deactivate")}
              >
                Deactivate
              </Button>
            </div>
            {branch.status === "suspended" ? (
              <p className="text-sm text-[var(--color-burnt-orange)]">
                This branch is suspended by the platform and cannot be reactivated here.
              </p>
            ) : null}
          </section>

          <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
            <h2 className="text-xl">Staff</h2>
            <ul className="space-y-2">
              {(staffQuery.data ?? []).map((member) => (
                <li
                  key={member.user_id}
                  className="flex items-center justify-between gap-2 rounded-md border border-black/5 px-3 py-2 text-sm"
                >
                  <div>
                    <p className="font-medium">{member.name}</p>
                    <p className="text-black/55">
                      {member.email} · {member.role}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => removeStaff.mutate(member.user_id)}
                  >
                    Remove
                  </Button>
                </li>
              ))}
            </ul>

            <div className="space-y-3 border-t border-black/5 pt-4">
              <h3 className="text-sm font-semibold">Pending invitations</h3>
              <ul className="space-y-2">
                {(invitationsQuery.data ?? [])
                  .filter((inv) => inv.status === "pending")
                  .map((inv) => (
                    <li
                      key={inv.public_id}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-black/5 px-3 py-2 text-sm"
                    >
                      <div>
                        <p className="font-medium">{inv.full_name || inv.email}</p>
                        <p className="text-black/55">
                          {inv.email} · {inv.role} · expires{" "}
                          {inv.expires_at
                            ? new Date(inv.expires_at).toLocaleString()
                            : "soon"}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={resendInvite.isPending}
                          onClick={() => resendInvite.mutate(inv.public_id)}
                        >
                          Resend
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={revokeInvite.isPending}
                          onClick={() => revokeInvite.mutate(inv.public_id)}
                        >
                          Revoke
                        </Button>
                      </div>
                    </li>
                  ))}
                {(invitationsQuery.data ?? []).filter((inv) => inv.status === "pending")
                  .length === 0 ? (
                  <li className="text-sm text-black/50">No pending invitations.</li>
                ) : null}
              </ul>
            </div>

            <div className="space-y-3 border-t border-black/5 pt-4">
              <h3 className="text-sm font-semibold">Invite staff</h3>
              <p className="text-sm text-black/55">
                They receive a secure email and create their own password. No password is shown
                here.
              </p>
              <Field label="First name">
                <Input value={staffFirst} onChange={(e) => setStaffFirst(e.target.value)} />
              </Field>
              <Field label="Last name">
                <Input value={staffLast} onChange={(e) => setStaffLast(e.target.value)} />
              </Field>
              <Field label="Email">
                <Input value={staffEmail} onChange={(e) => setStaffEmail(e.target.value)} />
              </Field>
              <Field label="Role">
                <Select value={staffRole} onChange={(e) => setStaffRole(e.target.value)}>
                  <option value="branch_manager">Branch manager</option>
                  <option value="order_manager">Order manager</option>
                  <option value="kitchen_staff">Kitchen staff</option>
                  <option value="inventory_manager">Inventory manager</option>
                  <option value="delivery_staff">Delivery staff</option>
                </Select>
              </Field>
              <Button
                onClick={() => inviteStaff.mutate()}
                disabled={inviteStaff.isPending || !staffEmail}
              >
                Send invitation
              </Button>
            </div>
          </section>
        </div>
      ) : null}
    </AdminShell>
  );
}
