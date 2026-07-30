"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { restaurantNav } from "@/lib/admin-nav";
import { ApiError } from "@/lib/api/client";
import { restaurantStaffApi } from "@/features/restaurant/api/restaurant-staff-api";
import { useRestaurantProfile } from "@/features/restaurant/hooks/use-restaurant-profile";

export default function RestaurantStaffPage() {
  const profile = useRestaurantProfile();
  const { push } = useToast();
  const qc = useQueryClient();
  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<"restaurant_manager" | "restaurant_staff">(
    "restaurant_staff",
  );
  const [tempPassword, setTempPassword] = useState<string | null>(null);

  const staff = useQuery({
    queryKey: ["restaurant", "staff"],
    queryFn: async () => (await restaurantStaffApi.list()).data.staff,
  });

  const invite = useMutation({
    mutationFn: () =>
      restaurantStaffApi.invite({
        first_name: firstName,
        last_name: lastName,
        email,
        role,
      }),
    onSuccess: (res) => {
      setTempPassword(res.data.temporary_password);
      setFirstName("");
      setLastName("");
      setEmail("");
      qc.invalidateQueries({ queryKey: ["restaurant", "staff"] });
      push({ title: "Staff added", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Invite failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const revoke = useMutation({
    mutationFn: (userId: number) => restaurantStaffApi.revoke(userId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant", "staff"] });
      push({ title: "Access revoked", tone: "success" });
    },
    onError: (err: unknown) => {
      push({
        title: "Revoke failed",
        description: err instanceof ApiError ? err.message : "Request failed",
        tone: "error",
      });
    },
  });

  const brand = profile.data?.trading_name ?? "Restaurant";

  return (
    <AdminShell
      brand={brand}
      portalLabel="Restaurant Admin"
      items={restaurantNav}
      title="Staff"
      subtitle="Managers and staff for your restaurant only"
    >
      {staff.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : (staff.data?.length ?? 0) === 0 ? (
        <EmptyState title="No staff yet" description="Invite a manager or staff member." />
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="min-w-full text-sm">
            <thead className="bg-[var(--surface-muted)] text-left">
              <tr>
                <th className="p-3">Name</th>
                <th className="p-3">Role</th>
                <th className="p-3">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody>
              {staff.data?.map((member) => (
                <tr key={member.user_id} className="border-t">
                  <td className="p-3">
                    <p className="font-medium">{member.name}</p>
                    <p className="text-xs text-[var(--text-muted)]">{member.email}</p>
                  </td>
                  <td className="p-3">{(member.role ?? "").replaceAll("_", " ")}</td>
                  <td className="p-3">
                    <Badge tone={member.status === "active" ? "success" : "warning"}>
                      {member.status}
                    </Badge>
                  </td>
                  <td className="p-3">
                    {member.role !== "restaurant_owner" ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={revoke.isPending}
                        onClick={() => revoke.mutate(member.user_id)}
                      >
                        Revoke
                      </Button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <form
        className="mt-6 grid max-w-2xl gap-3 rounded-lg border bg-white p-5 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          invite.mutate();
        }}
      >
        <h2 className="sm:col-span-2 text-lg font-medium">Invite staff</h2>
        <Field label="First name">
          <Input required value={firstName} onChange={(e) => setFirstName(e.target.value)} />
        </Field>
        <Field label="Last name">
          <Input required value={lastName} onChange={(e) => setLastName(e.target.value)} />
        </Field>
        <Field label="Email">
          <Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="Role">
          <Select
            value={role}
            onChange={(e) =>
              setRole(e.target.value as "restaurant_manager" | "restaurant_staff")
            }
          >
            <option value="restaurant_manager">Manager</option>
            <option value="restaurant_staff">Staff</option>
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Button type="submit" disabled={invite.isPending}>
            {invite.isPending ? "Inviting…" : "Invite"}
          </Button>
        </div>
        {tempPassword ? (
          <p className="sm:col-span-2 text-sm text-[var(--text-secondary)]">
            Temporary password: <strong>{tempPassword}</strong>
          </p>
        ) : null}
      </form>
    </AdminShell>
  );
}
