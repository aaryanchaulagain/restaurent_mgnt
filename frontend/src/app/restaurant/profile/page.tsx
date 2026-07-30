"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Textarea } from "@/components/ui/forms";
import { restaurantNav } from "@/lib/admin-nav";
import {
  useActivateRestaurant,
  useRestaurantChecklist,
  useRestaurantProfile,
  useUpdateRestaurantProfile,
} from "@/features/restaurant/hooks/use-restaurant-profile";

export default function RestaurantProfilePage() {
  const profile = useRestaurantProfile();
  const checklist = useRestaurantChecklist();
  const update = useUpdateRestaurantProfile();
  const activate = useActivateRestaurant();

  if (profile.isLoading) {
    return (
      <AdminShell brand="Restaurant" portalLabel="Restaurant Admin" items={restaurantNav} title="Restaurant profile">
        <Skeleton className="h-8 w-48" />
      </AdminShell>
    );
  }

  const p = profile.data;

  return (
    <AdminShell
      brand={p?.trading_name ?? "Restaurant"}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Restaurant profile"
      subtitle="Public identity shown on Suvakamana marketplace pages"
      actions={
        <Button
          disabled={!checklist.data?.can_activate || activate.isPending}
          onClick={() => activate.mutate()}
        >
          {activate.isPending ? "Activating…" : "Publish restaurant"}
        </Button>
      }
    >
      {checklist.data ? (
        <section className="mb-6 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-semibold text-[var(--text-primary)]">Setup checklist</p>
              <p className="text-sm text-[var(--text-secondary)]">
                {checklist.data.completion_percentage}% complete
              </p>
            </div>
            <Badge tone={checklist.data.can_activate ? "success" : "warning"}>
              {checklist.data.can_activate ? "Ready to publish" : "Incomplete"}
            </Badge>
          </div>
          {checklist.data.missing.length > 0 ? (
            <p className="mt-2 text-xs text-[var(--text-muted)]">
              Missing: {checklist.data.missing.join(", ")}
            </p>
          ) : null}
        </section>
      ) : null}

      <form
        className="grid gap-6 lg:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          const form = new FormData(e.currentTarget);
          update.mutate({
            trading_name: String(form.get("trading_name") ?? ""),
            short_description: String(form.get("short_description") ?? ""),
            description: String(form.get("description") ?? ""),
            business_email: String(form.get("business_email") ?? ""),
            business_phone: String(form.get("business_phone") ?? ""),
          });
        }}
      >
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <Field label="Trading name" htmlFor="trading_name">
            <Input id="trading_name" name="trading_name" defaultValue={p?.trading_name ?? ""} />
          </Field>
          <Field label="Short description" htmlFor="short_description">
            <Input id="short_description" name="short_description" defaultValue={p?.short_description ?? ""} />
          </Field>
          <Field label="Business email" htmlFor="business_email">
            <Input id="business_email" name="business_email" defaultValue={p?.business_email ?? ""} />
          </Field>
          <Field label="Business phone" htmlFor="business_phone">
            <Input id="business_phone" name="business_phone" defaultValue={p?.business_phone ?? ""} />
          </Field>
          <Field label="Description" htmlFor="desc">
            <Textarea id="desc" name="description" defaultValue={p?.description ?? ""} />
          </Field>
          {p?.slug ? (
            <p className="text-xs text-[var(--text-muted)]">
              Public URL preview: /restaurants/{p.slug}
            </p>
          ) : null}
        </section>
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <p className="text-sm text-[var(--text-secondary)]">
            Logo: {p?.logo_path ? "Uploaded" : "Not set"} · Cover: {p?.cover_image_path ? "Uploaded" : "Not set"}
          </p>
          <p className="text-sm text-[var(--text-secondary)]">
            Status: <strong>{p?.status}</strong>
            {p?.published_at ? " · Published" : ""}
          </p>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending ? "Saving…" : "Save profile"}
          </Button>
          {update.isError ? (
            <p className="text-sm text-red-600">Unable to save profile. Check your permissions and try again.</p>
          ) : null}
        </section>
      </form>
    </AdminShell>
  );
}
