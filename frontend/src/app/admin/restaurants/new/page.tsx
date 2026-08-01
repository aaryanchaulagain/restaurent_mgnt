"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { adminNav } from "@/lib/admin-nav";
import { PLATFORM_NAME, VENDOR_TYPES, type VendorType, vendorTypeLabel } from "@/lib/brand";
import {
  fieldErrorsOf,
  firstFieldError,
  summarizeErrors,
  type FieldErrors,
} from "@/lib/api/form-errors";
import { adminRestaurantApi } from "@/features/admin/api/admin-restaurant-api";

export default function AdminProvisionRestaurantPage() {
  const router = useRouter();
  const { push } = useToast();
  const [tradingName, setTradingName] = useState("");
  const [legalName, setLegalName] = useState("");
  const [businessEmail, setBusinessEmail] = useState("");
  const [businessPhone, setBusinessPhone] = useState("");
  const [description, setDescription] = useState("");
  const [vendorType, setVendorType] = useState<VendorType>("restaurant");
  const [ownership, setOwnership] = useState<"third_party" | "first_party">("third_party");
  const [commission, setCommission] = useState("12.5");
  const [activateNow, setActivateNow] = useState(false);
  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [credentials, setCredentials] = useState<{
    email: string;
    password: string | null;
    publicId: string;
    vendorType: VendorType;
  } | null>(null);
  const [errors, setErrors] = useState<FieldErrors>({});

  const typeLabel = vendorTypeLabel(vendorType);
  const errorOf = (key: string) => firstFieldError(errors, key);

  const provision = useMutation({
    onMutate: () => setErrors({}),
    mutationFn: () =>
      adminRestaurantApi.provision({
        trading_name: tradingName,
        legal_business_name: legalName || tradingName,
        business_email: businessEmail || undefined,
        business_phone: businessPhone || undefined,
        description: description || undefined,
        vendor_type: vendorType,
        ownership_type: ownership,
        activate_now: activateNow,
        commission_rate: Number(commission) || undefined,
        owner: {
          first_name: firstName,
          last_name: lastName,
          email,
          password: password || undefined,
        },
      }),
    onSuccess: (res) => {
      const temp = res.data.temporary_password;
      setCredentials({
        email: res.data.owner.email,
        password: temp,
        publicId: res.data.restaurant.public_id,
        vendorType,
      });
      push({
        title: `${typeLabel} admin created`,
        description: res.data.restaurant.trading_name,
        tone: "success",
      });
    },
    onError: (err: unknown) => {
      setErrors(fieldErrorsOf(err));
      push({
        title: "Could not provision",
        description: summarizeErrors(err),
        tone: "error",
      });
    },
  });

  return (
    <AdminShell
      brand={PLATFORM_NAME}
      portalLabel="Super Admin"
      items={adminNav}
      title="Add partner business"
      subtitle="Create a restaurant, bakery, butchery or grocery — and their admin dashboard login"
      actions={
        <Link href="/admin/restaurants">
          <Button variant="outline">Back</Button>
        </Link>
      }
    >
      {credentials ? (
        <section className="max-w-xl space-y-4 rounded-lg border bg-white p-6">
          <h2 className="text-xl font-medium">Credentials</h2>
          <p className="text-sm text-[var(--text-secondary)]">
            Share these with the {vendorTypeLabel(credentials.vendorType).toLowerCase()} owner.
            The temporary password is shown once.
          </p>
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between gap-3">
              <dt className="text-[var(--text-muted)]">Type</dt>
              <dd className="font-medium">{vendorTypeLabel(credentials.vendorType)}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-[var(--text-muted)]">Email</dt>
              <dd className="font-medium">{credentials.email}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-[var(--text-muted)]">Password</dt>
              <dd className="font-medium">
                {credentials.password ?? "(existing account — password unchanged)"}
              </dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-[var(--text-muted)]">Portal</dt>
              <dd>/restaurant/dashboard</dd>
            </div>
          </dl>
          <div className="flex flex-wrap gap-2 pt-2">
            <Button onClick={() => router.push(`/admin/restaurants/${credentials.publicId}`)}>
              Open business
            </Button>
            <Button variant="outline" onClick={() => router.push("/admin/restaurants")}>
              Back to list
            </Button>
          </div>
        </section>
      ) : (
        <form
          className="grid max-w-3xl gap-6"
          onSubmit={(e) => {
            e.preventDefault();
            provision.mutate();
          }}
        >
          {Object.keys(errors).length > 0 ? (
            <div
              className="rounded-lg border border-[rgba(163,59,45,0.35)] bg-[rgba(163,59,45,0.06)] p-4 text-sm text-[var(--color-error)]"
              role="alert"
            >
              <p className="font-medium">Please fix the following:</p>
              <ul className="mt-2 list-disc space-y-1 pl-5">
                {Object.entries(errors).flatMap(([key, messages]) =>
                  messages.map((message) => <li key={`${key}-${message}`}>{message}</li>),
                )}
              </ul>
            </div>
          ) : null}

          <section className="grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
            <h2 className="sm:col-span-2 text-xl">Business</h2>
            <Field label="Business type" htmlFor="vendor" error={errorOf("vendor_type")}>
              <Select
                id="vendor"
                value={vendorType}
                onChange={(e) => setVendorType(e.target.value as VendorType)}
              >
                {VENDOR_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Trading name" htmlFor="trading" error={errorOf("trading_name")}>
              <Input
                id="trading"
                required
                value={tradingName}
                onChange={(e) => setTradingName(e.target.value)}
                placeholder="e.g. Aryan Bakery"
              />
            </Field>
            <Field
              label="Legal business name"
              htmlFor="legal"
              error={errorOf("legal_business_name")}
            >
              <Input
                id="legal"
                value={legalName}
                onChange={(e) => setLegalName(e.target.value)}
                placeholder="Defaults to trading name"
              />
            </Field>
            <Field label="Business email" htmlFor="bemail" error={errorOf("business_email")}>
              <Input
                id="bemail"
                type="email"
                value={businessEmail}
                onChange={(e) => setBusinessEmail(e.target.value)}
              />
            </Field>
            <Field label="Business phone" htmlFor="bphone" error={errorOf("business_phone")}>
              <Input
                id="bphone"
                value={businessPhone}
                onChange={(e) => setBusinessPhone(e.target.value)}
              />
            </Field>
            <Field label="Ownership" htmlFor="ownership" error={errorOf("ownership_type")}>
              <Select
                id="ownership"
                value={ownership}
                onChange={(e) => setOwnership(e.target.value as "third_party" | "first_party")}
              >
                <option value="third_party">Partner on {PLATFORM_NAME}</option>
                <option value="first_party">{PLATFORM_NAME}-operated</option>
              </Select>
            </Field>
            <Field label="Commission %" htmlFor="commission" error={errorOf("commission_rate")}>
              <Input
                id="commission"
                type="number"
                step="0.01"
                value={commission}
                onChange={(e) => setCommission(e.target.value)}
              />
            </Field>
            <Field
              label="Description"
              htmlFor="desc"
              className="sm:col-span-2"
              error={errorOf("description")}
            >
              <Textarea
                id="desc"
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </Field>
            <label className="sm:col-span-2 flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={activateNow}
                onChange={(e) => setActivateNow(e.target.checked)}
              />
              Publish as active now (skip pending setup)
            </label>
          </section>

          <section className="grid gap-4 rounded-lg border bg-white p-5 sm:grid-cols-2">
            <h2 className="sm:col-span-2 text-xl">Owner login</h2>
            <Field label="First name" htmlFor="fn" error={errorOf("owner.first_name")}>
              <Input
                id="fn"
                required
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
              />
            </Field>
            <Field label="Last name" htmlFor="ln" error={errorOf("owner.last_name")}>
              <Input
                id="ln"
                required
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
              />
            </Field>
            <Field label="Email" htmlFor="email" error={errorOf("owner.email")}>
              <Input
                id="email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </Field>
            <Field
              label="Password (optional)"
              htmlFor="pw"
              hint="At least 8 characters with upper and lower case letters and a number. Leave blank to auto-generate."
              error={errorOf("owner.password")}
            >
              <Input
                id="pw"
                type="text"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Auto-generated if empty"
              />
            </Field>
          </section>

          <div>
            <Button type="submit" disabled={provision.isPending}>
              {provision.isPending ? "Creating…" : `Create ${typeLabel.toLowerCase()} admin`}
            </Button>
          </div>
        </form>
      )}
    </AdminShell>
  );
}
