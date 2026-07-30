"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input, Select } from "@/components/ui/forms";
import { Badge, Skeleton } from "@/components/ui/feedback";
import {
  restaurantHoursApi,
  type OpeningHourRow,
  type SpecialHourRow,
} from "@/features/restaurant/api/restaurant-admin-api";
import { ApiError } from "@/lib/api/client";

const DAY_LABELS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

type PeriodDraft = {
  key: string;
  day_of_week: number;
  opens_at: string;
  closes_at: string;
  service_type: OpeningHourRow["service_type"];
};

function emptyWeek(): PeriodDraft[] {
  return DAY_LABELS.map((_, day) => ({
    key: `closed-${day}`,
    day_of_week: day,
    opens_at: "11:00",
    closes_at: "21:00",
    service_type: "all" as const,
  }));
}

export function RestaurantHoursEditor() {
  const qc = useQueryClient();
  const [serviceTab, setServiceTab] = useState<OpeningHourRow["service_type"]>("all");
  const [periods, setPeriods] = useState<PeriodDraft[]>(emptyWeek());
  const [closedDays, setClosedDays] = useState<Record<number, boolean>>({});
  const [dirty, setDirty] = useState(false);
  const [saved, setSaved] = useState(false);
  const [validationSummary, setValidationSummary] = useState<string[]>([]);
  const [specialDate, setSpecialDate] = useState("");
  const [specialClosed, setSpecialClosed] = useState(true);
  const [specialReason, setSpecialReason] = useState("");

  const hoursQuery = useQuery({
    queryKey: ["restaurant", "hours"],
    queryFn: async () => (await restaurantHoursApi.getHours()).data.hours,
  });

  const previewQuery = useQuery({
    queryKey: ["restaurant", "hours-preview"],
    queryFn: async () => (await restaurantHoursApi.getPreview()).data,
    refetchInterval: 60_000,
  });

  const specialQuery = useQuery({
    queryKey: ["restaurant", "special-hours"],
    queryFn: async () => (await restaurantHoursApi.listSpecial()).data.special_hours,
  });

  const derivedFromApi = useMemo(() => {
    if (!hoursQuery.data) return null;
    const rows = hoursQuery.data.filter((h) => h.service_type === serviceTab);
    const closed: Record<number, boolean> = {};
    const next: PeriodDraft[] = [];
    for (let day = 0; day < 7; day++) {
      const dayRows = rows.filter((r) => r.day_of_week === day);
      const closedRow = dayRows.find((r) => r.is_closed);
      if (closedRow || dayRows.length === 0) {
        closed[day] = Boolean(closedRow?.is_closed ?? dayRows.length === 0);
      }
      dayRows
        .filter((r) => !r.is_closed)
        .forEach((r, i) => {
          next.push({
            key: `${day}-${i}-${r.id ?? i}`,
            day_of_week: day,
            opens_at: (r.opens_at ?? "11:00").slice(0, 5),
            closes_at: (r.closes_at ?? "21:00").slice(0, 5),
            service_type: serviceTab,
          });
        });
    }
    return { closed, periods: next.length ? next : emptyWeek().map((p) => ({ ...p, service_type: serviceTab })) };
  }, [hoursQuery.data, serviceTab]);

  const prevDerivedRef = useRef(derivedFromApi);
  useEffect(() => {
    if (derivedFromApi && derivedFromApi !== prevDerivedRef.current) {
      prevDerivedRef.current = derivedFromApi;
      setClosedDays(derivedFromApi.closed);
      setPeriods(derivedFromApi.periods);
      setDirty(false);
    }
  }, [derivedFromApi]);

  useEffect(() => {
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (dirty) e.preventDefault();
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [dirty]);

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload: OpeningHourRow[] = [];
      for (let day = 0; day < 7; day++) {
        if (closedDays[day]) {
          payload.push({ day_of_week: day, is_closed: true, opens_at: null, closes_at: null, service_type: serviceTab });
          continue;
        }
        periods
          .filter((p) => p.day_of_week === day && p.service_type === serviceTab)
          .forEach((p) => {
            payload.push({
              day_of_week: day,
              is_closed: false,
              opens_at: p.opens_at,
              closes_at: p.closes_at,
              service_type: serviceTab,
            });
          });
      }
      const other = (hoursQuery.data ?? []).filter((h) => h.service_type !== serviceTab);
      return restaurantHoursApi.saveHours([...other, ...payload]);
    },
    onSuccess: () => {
      setDirty(false);
      setSaved(true);
      setValidationSummary([]);
      qc.invalidateQueries({ queryKey: ["restaurant", "hours"] });
      qc.invalidateQueries({ queryKey: ["restaurant", "hours-preview"] });
      setTimeout(() => setSaved(false), 3000);
    },
    onError: (e) => {
      if (e instanceof ApiError && e.errors) {
        setValidationSummary(Object.values(e.errors).flat());
      } else {
        setValidationSummary([e instanceof Error ? e.message : "Save failed"]);
      }
    },
  });

  const addPeriod = (day: number) => {
    setPeriods((prev) => [
      ...prev,
      { key: `${day}-${Date.now()}`, day_of_week: day, opens_at: "11:00", closes_at: "14:00", service_type: serviceTab },
    ]);
    setDirty(true);
  };

  const copyDayTo = (fromDay: number, toDays: number[]) => {
    const source = periods.filter((p) => p.day_of_week === fromDay && p.service_type === serviceTab);
    setPeriods((prev) => {
      const without = prev.filter((p) => !toDays.includes(p.day_of_week) || p.service_type !== serviceTab);
      const copies = toDays.flatMap((day) =>
        source.map((s, i) => ({ ...s, key: `${day}-copy-${i}-${Date.now()}`, day_of_week: day })),
      );
      return [...without, ...copies];
    });
    setClosedDays((prev) => {
      const next = { ...prev };
      for (const d of toDays) next[d] = prev[fromDay] ?? false;
      return next;
    });
    setDirty(true);
  };

  const grouped = useMemo(() => {
    const map: Record<number, PeriodDraft[]> = {};
    for (let d = 0; d < 7; d++) map[d] = periods.filter((p) => p.day_of_week === d && p.service_type === serviceTab);
    return map;
  }, [periods, serviceTab]);

  if (hoursQuery.isLoading) {
    return <Skeleton className="h-96 w-full" aria-label="Loading hours" />;
  }

  return (
    <div className="space-y-8">
      {previewQuery.data ? (
        <div className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-4 text-sm">
          <p className="font-medium">Live preview ({previewQuery.data.timezone})</p>
          <div className="mt-2 flex flex-wrap gap-2">
            <Badge tone={previewQuery.data.is_open ? "success" : "error"}>
              {previewQuery.data.is_open ? "Open now" : "Closed now"}
            </Badge>
            <Badge tone="neutral">Pickup: {previewQuery.data.is_open_pickup ? "Open" : "Closed"}</Badge>
            <Badge tone="neutral">Delivery: {previewQuery.data.is_open_delivery ? "Open" : "Closed"}</Badge>
          </div>
          {previewQuery.data.next_opening_time ? (
            <p className="mt-2 text-[var(--text-muted)]">
              Next opening: {new Date(previewQuery.data.next_opening_time).toLocaleString()}
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2">
        {(["all", "pickup", "restaurant_delivery"] as const).map((tab) => (
          <Button key={tab} variant={serviceTab === tab ? "primary" : "outline"} size="sm" onClick={() => setServiceTab(tab)}>
            {tab === "all" ? "General" : tab === "pickup" ? "Pickup" : "Delivery"}
          </Button>
        ))}
      </div>

      {validationSummary.length ? (
        <div className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
          <p className="font-medium">Fix the following before saving:</p>
          <ul className="mt-2 list-disc pl-5">
            {validationSummary.map((msg) => (
              <li key={msg}>{msg}</li>
            ))}
          </ul>
        </div>
      ) : null}

      {saved ? <p className="text-sm text-green-700">Hours saved.</p> : null}

      <div className="space-y-6">
        {DAY_LABELS.map((label, day) => (
          <section key={label} className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 className="text-lg font-semibold">{label}</h3>
              <Checkbox
                label="Closed all day"
                checked={Boolean(closedDays[day])}
                onChange={(e) => {
                  setClosedDays((prev) => ({ ...prev, [day]: e.target.checked }));
                  setDirty(true);
                }}
              />
            </div>
            {!closedDays[day] ? (
              <div className="mt-4 space-y-3">
                {(grouped[day] ?? []).map((period, idx) => (
                  <div key={period.key} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <Field label="Opens" htmlFor={`open-${day}-${idx}`}>
                      <Input
                        id={`open-${day}-${idx}`}
                        type="time"
                        value={period.opens_at}
                        onChange={(e) => {
                          setPeriods((prev) =>
                            prev.map((p) => (p.key === period.key ? { ...p, opens_at: e.target.value } : p)),
                          );
                          setDirty(true);
                        }}
                      />
                    </Field>
                    <Field label="Closes" htmlFor={`close-${day}-${idx}`}>
                      <Input
                        id={`close-${day}-${idx}`}
                        type="time"
                        value={period.closes_at}
                        onChange={(e) => {
                          setPeriods((prev) =>
                            prev.map((p) => (p.key === period.key ? { ...p, closes_at: e.target.value } : p)),
                          );
                          setDirty(true);
                        }}
                      />
                    </Field>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="self-end"
                      onClick={() => {
                        setPeriods((prev) => prev.filter((p) => p.key !== period.key));
                        setDirty(true);
                      }}
                    >
                      Remove
                    </Button>
                  </div>
                ))}
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={() => addPeriod(day)}>
                    Add period
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={() => copyDayTo(day, [day])}>
                    Clear periods
                  </Button>
                </div>
              </div>
            ) : null}
          </section>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" onClick={() => copyDayTo(1, [1, 2, 3, 4, 5])}>
          Copy Monday → weekdays
        </Button>
        <Button type="button" disabled={saveMutation.isPending || !dirty} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? "Saving…" : "Save hours"}
        </Button>
      </div>

      <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4">
        <h3 className="text-lg font-semibold">Special hours & closures</h3>
        <p className="mt-1 text-sm text-[var(--text-muted)]">Public holidays, temporary closures, or special trading hours.</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Field label="Date" htmlFor="special-date">
            <Input id="special-date" type="date" value={specialDate} onChange={(e) => setSpecialDate(e.target.value)} />
          </Field>
          <Field label="Type" htmlFor="special-type">
            <Select
              id="special-type"
              value={specialClosed ? "closed" : "open"}
              onChange={(e) => setSpecialClosed(e.target.value === "closed")}
            >
              <option value="closed">Closed</option>
              <option value="open">Special open hours</option>
            </Select>
          </Field>
          <Field label="Reason" htmlFor="special-reason">
            <Input id="special-reason" value={specialReason} onChange={(e) => setSpecialReason(e.target.value)} />
          </Field>
        </div>
        <Button
          className="mt-4"
          type="button"
          variant="outline"
          disabled={!specialDate}
          onClick={async () => {
            await restaurantHoursApi.createSpecial({
              date: specialDate,
              is_closed: specialClosed,
              opens_at: specialClosed ? null : "11:00",
              closes_at: specialClosed ? null : "21:00",
              reason: specialReason || null,
            });
            qc.invalidateQueries({ queryKey: ["restaurant", "special-hours"] });
            setSpecialDate("");
          }}
        >
          Add special date
        </Button>
        <ul className="mt-4 space-y-2 text-sm">
          {(specialQuery.data ?? []).map((s: SpecialHourRow) => (
            <li key={s.id} className="flex justify-between gap-2 border-b pb-2">
              <span>
                {s.date} — {s.is_closed ? `Closed${s.reason ? `: ${s.reason}` : ""}` : `${s.opens_at}–${s.closes_at}`}
              </span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={async () => {
                  await restaurantHoursApi.deleteSpecial(s.id);
                  qc.invalidateQueries({ queryKey: ["restaurant", "special-hours"] });
                }}
              >
                Remove
              </Button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
