"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { restaurantNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";
import { businessBranchApi } from "@/features/business/api/business-branch-api";
import { setBranchDashboardContext } from "@/features/business/lib/branch-context";

export default function BranchSettingsPage() {
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
  const [tempPassword, setTempPassword] = useState<string | null>(null);

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

  const assignStaff = useMutation({
    mutationFn: () =>
      businessBranchApi.assignBranchUser(businessPublicId, branchPublicId, {
        email: staffEmail,
        first_name: staffFirst,
        last_name: staffLast,
        role: staffRole,
      }),
    onSuccess: (res) => {
      setTempPassword(res.data.temporary_password);
      setStaffEmail("");
      setStaffFirst("");
      setStaffLast("");
      qc.invalidateQueries({ queryKey: ["branch-staff", businessPublicId, branchPublicId] });
      push({ title: "Staff assigned", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Assignment failed",
        description: err instanceof ApiError ? err.message : "Request failed",
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
  });

  return (
    <AdminShell
      brand={branch?.business_name ?? "Business"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
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
                onClick={() => assignStaff.mutate()}
                disabled={assignStaff.isPending || !staffEmail}
              >
                Assign staff
              </Button>
              {tempPassword ? (
                <p className="text-sm text-black/70">
                  Temporary password: <code>{tempPassword}</code>
                </p>
              ) : null}
            </div>
          </section>
        </div>
      ) : null}
    </AdminShell>
  );
}
