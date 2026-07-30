"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Field, FileUpload, Input, Switch, Textarea } from "@/components/ui/forms";
import { restaurantNav } from "@/lib/admin-nav";
import { useState } from "react";

export default function RestaurantSettingsPage() {
  const [delivery, setDelivery] = useState(true);
  const [pickup, setPickup] = useState(true);

  return (
    <AdminShell
      brand="Himalayan Kitchen"
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Settings"
      subtitle="Profile, hours and fulfilment preferences"
      actions={<Button>Save changes</Button>}
    >
      <div className="grid gap-6 lg:grid-cols-2">
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Restaurant profile</h2>
          <Field label="Display name" htmlFor="name">
            <Input id="name" defaultValue="Himalayan Kitchen" />
          </Field>
          <Field label="Description" htmlFor="desc">
            <Textarea
              id="desc"
              defaultValue="Mountain-inspired thalis, smoky tandoor breads and slow-cooked dals."
            />
          </Field>
          <FileUpload label="Upload logo" />
          <FileUpload label="Upload cover image" />
        </section>
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <h2 className="text-2xl">Service settings</h2>
          <Switch label="Accept delivery orders" checked={delivery} onChange={setDelivery} />
          <Switch label="Accept pickup orders" checked={pickup} onChange={setPickup} />
          <Field label="Weekday hours" htmlFor="hours">
            <Input id="hours" defaultValue="10:00 – 22:00" />
          </Field>
          <Field label="Preparation time (minutes)" htmlFor="prep">
            <Input id="prep" defaultValue="25" />
          </Field>
        </section>
      </div>
    </AdminShell>
  );
}
