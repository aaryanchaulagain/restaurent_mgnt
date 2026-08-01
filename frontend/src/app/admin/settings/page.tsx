"use client";

import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { Field, Input, Switch } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { useState } from "react";

export default function AdminSettingsPage() {
  const [maintenance, setMaintenance] = useState(false);
  const [featured, setFeatured] = useState(true);

  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Platform settings"
      subtitle="Global marketplace configuration"
      actions={<Button>Save settings</Button>}
    >
      <div className="grid gap-6 lg:grid-cols-2">
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <Field label="Support email" htmlFor="support">
            <Input id="support" defaultValue="support@suvakamana.local" />
          </Field>
          <Field label="Default service fee (cents)" htmlFor="fee">
            <Input id="fee" defaultValue="149" />
          </Field>
          <Field label="Default currency" htmlFor="currency">
            <Input id="currency" defaultValue="USD" />
          </Field>
        </section>
        <section className="space-y-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5">
          <Switch
            label="Maintenance mode"
            checked={maintenance}
            onChange={setMaintenance}
          />
          <Switch
            label="Show featured restaurants on home"
            checked={featured}
            onChange={setFeatured}
          />
        </section>
      </div>
    </AdminShell>
  );
}
