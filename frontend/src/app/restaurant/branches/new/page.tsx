"use client";

import { Suspense, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";
import { ApiError } from "@/lib/api/client";
import { businessBranchApi } from "@/features/business/api/business-branch-api";
import { setBranchDashboardContext } from "@/features/business/lib/branch-context";

function NewBranchForm() {
  const { items: navItems, portalLabel: shellPortalLabel } = useRestaurantShell();
  const router = useRouter();
  const params = useSearchParams();
  const { push } = useToast();
  const qc = useQueryClient();

  const context = useQuery({
    queryKey: ["business-branch-context"],
    queryFn: async () => (await businessBranchApi.context()).data,
  });

  const businessPublicId = useMemo(
    () => params.get("business") ?? context.data?.businesses[0]?.public_id ?? "",
    [params, context.data],
  );

  const [name, setName] = useState("");
  const [code, setCode] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [addressLine, setAddressLine] = useState("");
  const [city, setCity] = useState("");
  const [state, setState] = useState("");
  const [postcode, setPostcode] = useState("");
  const [country, setCountry] = useState("AU");
  const [timezone, setTimezone] = useState("Australia/Sydney");
  const [status, setStatus] = useState("draft");
  const [inviteManager, setInviteManager] = useState(false);
  const [managerName, setManagerName] = useState("");
  const [managerEmail, setManagerEmail] = useState("");
  const [managerPhone, setManagerPhone] = useState("");

  const create = useMutation({
    mutationFn: () =>
      businessBranchApi.createBranch(businessPublicId, {
        name,
        code: code || undefined,
        email: email || undefined,
        phone: phone || undefined,
        address_line: addressLine || undefined,
        city: city || undefined,
        state: state || undefined,
        postcode: postcode || undefined,
        country: country || undefined,
        timezone: timezone || undefined,
        status,
        invite_manager: inviteManager,
        manager_full_name: inviteManager ? managerName || undefined : undefined,
        manager_email: inviteManager ? managerEmail || undefined : undefined,
        manager_phone: inviteManager ? managerPhone || undefined : undefined,
        manager_role: inviteManager ? "branch_manager" : undefined,
      }),
    onSuccess: (res) => {
      const branch = res.data.branch;
      setBranchDashboardContext({
        businessPublicId,
        branchPublicId: branch.public_id,
        restaurantPublicId: res.data.restaurant_public_id,
        aggregate: false,
      });
      qc.invalidateQueries({ queryKey: ["business-branch-context"] });
      push({
        title: "Branch created",
        description: res.data.invitation
          ? "Manager invitation sent. They will create their own password."
          : "Configure menus, hours, delivery, and payments before activating.",
        tone: "success",
      });
      router.push(`/restaurant/branches/${businessPublicId}/${branch.public_id}`);
    },
    onError: (err: unknown) => {
      push({
        title: "Could not create branch",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const brand =
    context.data?.businesses.find((b) => b.public_id === businessPublicId)?.name ?? "Business";

  return (
    <AdminShell
      brand={brand}
      portalLabel={shellPortalLabel}
      items={navItems}
      title="Add branch"
      subtitle="Creates a draft location and linked operational restaurant"
    >
      <form
        className="mx-auto max-w-2xl space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5"
        onSubmit={(e) => {
          e.preventDefault();
          if (!businessPublicId) return;
          create.mutate();
        }}
      >
        <Field label="Branch name">
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </Field>
        <Field label="Branch code">
          <Input
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            placeholder="ITAHARI"
          />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Email">
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Field label="Phone">
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
          </Field>
        </div>
        <Field label="Address">
          <Input value={addressLine} onChange={(e) => setAddressLine(e.target.value)} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="City">
            <Input value={city} onChange={(e) => setCity(e.target.value)} />
          </Field>
          <Field label="State">
            <Input value={state} onChange={(e) => setState(e.target.value)} />
          </Field>
          <Field label="Postcode">
            <Input value={postcode} onChange={(e) => setPostcode(e.target.value)} />
          </Field>
          <Field label="Country">
            <Input
              value={country}
              onChange={(e) => setCountry(e.target.value.toUpperCase())}
              maxLength={2}
            />
          </Field>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Timezone">
            <Input value={timezone} onChange={(e) => setTimezone(e.target.value)} />
          </Field>
          <Field label="Initial status">
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="paused">Paused</option>
              <option value="inactive">Inactive</option>
            </Select>
          </Field>
        </div>
        <p className="text-sm text-black/55">
          Menus, opening hours, delivery area, and payment methods are configured after creation
          using this branch’s linked restaurant.
        </p>

        <div className="space-y-3 rounded-[var(--radius-md)] border border-black/10 bg-[var(--surface-sunken,#f7f4ef)] p-4">
          <label className="flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={inviteManager}
              onChange={(e) => setInviteManager(e.target.checked)}
            />
            <span>
              <strong>Invite a branch manager now</strong>
              <span className="mt-1 block text-black/60">
                The manager will receive a secure email invitation to create their own password.
                You will never see or set their password.
              </span>
            </span>
          </label>
          {inviteManager ? (
            <div className="space-y-3 pt-1">
              <Field label="Manager full name">
                <Input
                  value={managerName}
                  onChange={(e) => setManagerName(e.target.value)}
                  required={inviteManager}
                />
              </Field>
              <Field label="Manager email">
                <Input
                  type="email"
                  value={managerEmail}
                  onChange={(e) => setManagerEmail(e.target.value)}
                  required={inviteManager}
                />
              </Field>
              <Field label="Manager phone">
                <Input value={managerPhone} onChange={(e) => setManagerPhone(e.target.value)} />
              </Field>
            </div>
          ) : null}
        </div>

        <Button
          type="submit"
          disabled={
            create.isPending ||
            !name ||
            !businessPublicId ||
            (inviteManager && (!managerName || !managerEmail))
          }
        >
          {create.isPending ? "Creating…" : "Create draft branch"}
        </Button>
      </form>
    </AdminShell>
  );
}

export default function NewBranchPage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-black/50">Loading…</div>}>
      <NewBranchForm />
    </Suspense>
  );
}
