"use client";

import { useMemo, useState } from "react";
import { AdminShell } from "@/components/layout/admin-shell";
import { Field, Input, Select } from "@/components/ui/forms";
import { adminNav } from "@/lib/admin-nav";
import { AdminApplicationTable } from "@/features/partner/components/AdminApplicationTable";
import {
  ADMIN_SORT_OPTIONS,
  ADMIN_STATUS_FILTERS,
} from "@/features/partner/constants";
import { useAdminApplications } from "@/features/partner/hooks/use-partner-application";
import { Button } from "@/components/ui/button";

export default function AdminApplicationsPage() {
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [sort, setSort] = useState("newest");
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");

  const params = useMemo(
    () => ({ status: status || undefined, search: search || undefined, sort, page }),
    [status, search, sort, page],
  );

  const { data, isLoading } = useAdminApplications(params);
  const applications = data?.applications ?? [];
  const meta = data?.meta;

  return (
    <AdminShell
      brand="Khana"
      portalLabel="Super Admin"
      items={adminNav}
      title="Restaurant applications"
      subtitle={
        meta
          ? `${meta.total} application${meta.total === 1 ? "" : "s"} · page ${meta.current_page} of ${meta.last_page}`
          : "Review partner onboarding applications"
      }
    >
      <div className="mb-6 grid gap-4 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Status">
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            {ADMIN_STATUS_FILTERS.map((opt) => (
              <option key={opt.value || "all"} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Sort">
          <Select value={sort} onChange={(e) => { setSort(e.target.value); setPage(1); }}>
            {ADMIN_SORT_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Search" className="sm:col-span-2">
          <div className="flex gap-2">
            <Input
              placeholder="Name, ABN, email, reference…"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  setSearch(searchInput.trim());
                  setPage(1);
                }
              }}
            />
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setSearch(searchInput.trim());
                setPage(1);
              }}
            >
              Search
            </Button>
          </div>
        </Field>
      </div>

      <AdminApplicationTable applications={applications} loading={isLoading} />

      {meta && meta.last_page > 1 ? (
        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </Button>
          <span className="text-sm text-[var(--text-secondary)]">
            Page {meta.current_page} of {meta.last_page}
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={page >= meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      ) : null}
    </AdminShell>
  );
}
