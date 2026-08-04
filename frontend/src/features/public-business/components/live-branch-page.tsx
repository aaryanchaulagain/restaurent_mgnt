"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Breadcrumbs, useToast } from "@/components/ui/navigation";
import { Modal } from "@/components/ui/overlay";
import { SearchInput, Textarea } from "@/components/ui/forms";
import type { PublicMenuItem } from "@/features/cart/api/cart-api";
import { useCart } from "@/features/cart/components/cart-provider";
import { useAuth } from "@/features/auth/hooks/use-auth";
import {
  publicBusinessApi,
  publicBusinessQueryKeys,
} from "@/features/public-business/api/public-business-api";
import { formatCents } from "@/lib/utils";
import { pickCardImage } from "@/lib/media";

export function LiveBranchPage({
  businessSlug,
  branchPublicId,
}: {
  businessSlug: string;
  branchPublicId: string;
}) {
  const router = useRouter();
  const qc = useQueryClient();
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { push } = useToast();
  const { addItem } = useCart();
  const [menuQuery, setMenuQuery] = useState("");
  const [activeCategory, setActiveCategory] = useState<string | "all">("all");
  const [selected, setSelected] = useState<PublicMenuItem | null>(null);
  const [variantId, setVariantId] = useState<string | null>(null);
  const [modifierIds, setModifierIds] = useState<string[]>([]);
  const [qty, setQty] = useState(1);
  const [instructions, setInstructions] = useState("");

  useEffect(() => {
    setSelected(null);
    setActiveCategory("all");
    setMenuQuery("");
    void qc.cancelQueries({
      queryKey: ["public-business-branch-menu", businessSlug],
      exact: false,
    });
  }, [businessSlug, branchPublicId, qc]);

  const branchQuery = useQuery({
    queryKey: publicBusinessQueryKeys.branch(businessSlug, branchPublicId),
    queryFn: async () => (await publicBusinessApi.getBranch(businessSlug, branchPublicId)).data,
    retry: false,
  });

  const menuQueryResult = useQuery({
    queryKey: publicBusinessQueryKeys.menu(businessSlug, branchPublicId),
    queryFn: async () => (await publicBusinessApi.getBranchMenu(businessSlug, branchPublicId)).data,
    enabled: Boolean(branchQuery.data),
    retry: false,
    placeholderData: undefined,
  });

  const siblings = useQuery({
    queryKey: publicBusinessQueryKeys.branches(businessSlug),
    queryFn: async () => (await publicBusinessApi.listBranches(businessSlug)).data.branches,
    retry: false,
  });

  const categories = menuQueryResult.data?.categories ?? [];
  const items = useMemo(() => {
    if (menuQueryResult.isFetching && !menuQueryResult.data) return [];
    const list = menuQueryResult.data?.items ?? [];
    let filtered = list;
    if (activeCategory !== "all") {
      filtered = filtered.filter((i) => i.menu_category_public_id === activeCategory);
    }
    if (menuQuery.trim()) {
      const q = menuQuery.toLowerCase();
      filtered = filtered.filter((i) => i.name.toLowerCase().includes(q));
    }
    return filtered;
  }, [menuQueryResult.data, menuQueryResult.isFetching, menuQuery, activeCategory]);

  const openItem = (item: PublicMenuItem) => {
    setSelected(item);
    setQty(1);
    setInstructions("");
    const defaultVariant = item.variants.find((v) => v.is_default) ?? item.variants[0];
    setVariantId(defaultVariant?.public_id ?? null);
    setModifierIds(
      item.modifier_groups.flatMap((g) =>
        g.options.filter((o) => o.is_default).map((o) => o.public_id),
      ),
    );
  };

  const onAddToCart = async () => {
    if (!selected || !selected.is_available) return;
    if (authLoading) return;
    if (!isAuthenticated) {
      const next = encodeURIComponent(
        typeof window !== "undefined"
          ? window.location.pathname + window.location.search
          : `/businesses/${businessSlug}/branches/${branchPublicId}`,
      );
      router.push(`/login?next=${next}`);
      return;
    }
    try {
      const result = await addItem({
        menu_item_public_id: selected.public_id,
        variant_public_id: variantId ?? undefined,
        quantity: qty,
        modifier_option_public_ids: modifierIds,
        special_instructions: instructions || undefined,
      });
      if (!result.ok) return;
      push({ title: "Added to cart", description: `${selected.name} × ${qty}`, tone: "success" });
      setSelected(null);
    } catch (e) {
      push({
        title: "Could not add item",
        description: e instanceof Error ? e.message : "Try again",
        tone: "error",
      });
    }
  };

  if (branchQuery.isLoading) {
    return (
      <main className="mx-auto max-w-5xl px-4 py-12">
        <Skeleton className="h-48 w-full" />
      </main>
    );
  }

  if (branchQuery.isError || !branchQuery.data) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-20">
        <EmptyState title="Location not found" description="This location is unavailable." />
      </main>
    );
  }

  const { business, branch } = branchQuery.data;
  const locality = [branch.address.city, branch.address.state, branch.address.postcode]
    .filter(Boolean)
    .join(", ");
  const showMenuPlaceholder = menuQueryResult.isFetching || menuQueryResult.isLoading;

  return (
    <main className="min-h-screen bg-[var(--surface-page)] pb-16">
      <section className="border-b border-[var(--border-subtle)] bg-[linear-gradient(160deg,#1a120c_0%,#3d2418_55%,#6b3a22_100%)] px-4 py-10 text-white sm:px-6">
        <div className="mx-auto max-w-5xl space-y-4">
          <Breadcrumbs
            items={[
              { href: "/", label: "Home" },
              { href: `/businesses/${business.slug}`, label: business.name },
              { label: branch.name },
            ]}
          />
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-xs font-semibold tracking-[0.18em] text-white/55 uppercase">
                {business.name}
              </p>
              <h1 className="mt-2 font-[family-name:var(--font-display)] text-4xl sm:text-5xl">
                {branch.name}
              </h1>
              {locality ? <p className="mt-2 text-sm text-white/70">{locality}</p> : null}
              {branch.address.address_line ? (
                <p className="text-sm text-white/60">{branch.address.address_line}</p>
              ) : null}
            </div>
            <div className="flex flex-wrap gap-2">
              {branch.is_temporarily_closed ? (
                <Badge tone="warning">Temporarily closed</Badge>
              ) : branch.is_open_now ? (
                <Badge tone="success">Open now</Badge>
              ) : (
                <Badge tone="info">Closed now</Badge>
              )}
              {!branch.accepting_orders ? (
                <Badge tone="warning">Not accepting orders</Badge>
              ) : null}
            </div>
          </div>
          <div className="flex flex-wrap gap-3 text-xs text-white/65">
            {branch.capabilities.pickup_enabled ? <span>Pickup available</span> : null}
            {branch.capabilities.delivery_enabled ? <span>Delivery available</span> : null}
            {branch.next_opening_time ? <span>Next opening: {branch.next_opening_time}</span> : null}
          </div>
          {(siblings.data?.length ?? 0) > 1 ? (
            <label className="block max-w-sm text-sm">
              <span className="mb-1 block text-white/60">Switch location</span>
              <select
                className="w-full rounded-md border border-white/20 bg-black/30 px-3 py-2 text-white"
                value={branch.public_id}
                onChange={(e) => {
                  const nextId = e.target.value;
                  setSelected(null);
                  void qc.cancelQueries({
                    queryKey: publicBusinessQueryKeys.menu(businessSlug, branchPublicId),
                  });
                  void qc.removeQueries({
                    queryKey: publicBusinessQueryKeys.menu(businessSlug, branchPublicId),
                  });
                  router.push(`/businesses/${business.slug}/branches/${nextId}`);
                }}
                aria-label="Switch location"
              >
                {siblings.data?.map((b) => (
                  <option key={b.public_id} value={b.public_id}>
                    {b.name}
                    {b.is_temporarily_closed ? " (Temporarily closed)" : ""}
                  </option>
                ))}
              </select>
            </label>
          ) : null}
        </div>
      </section>

      <section className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <div className="mb-6 flex flex-wrap items-center gap-3">
          <SearchInput
            value={menuQuery}
            onChange={(e) => setMenuQuery(e.target.value)}
            placeholder="Search the menu"
            className="max-w-sm"
          />
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant={activeCategory === "all" ? "primary" : "secondary"}
              onClick={() => setActiveCategory("all")}
            >
              All
            </Button>
            {categories.map((c) => (
              <Button
                key={c.public_id}
                type="button"
                size="sm"
                variant={activeCategory === c.public_id ? "primary" : "secondary"}
                onClick={() => setActiveCategory(c.public_id)}
              >
                {c.name}
              </Button>
            ))}
          </div>
        </div>

        {showMenuPlaceholder ? <Skeleton className="h-64 w-full" /> : null}

        {!showMenuPlaceholder && items.length === 0 ? (
          <EmptyState title="No items" description="Nothing is available at this location yet." />
        ) : null}

        {!showMenuPlaceholder ? (
          <div className="grid gap-4">
            {items.map((item) => (
              <button
                key={item.public_id}
                type="button"
                onClick={() => openItem(item)}
                className="menu-item-card group flex w-full gap-4 overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-3 text-left shadow-[var(--shadow-sm)] transition hover:-translate-y-0.5 hover:border-[rgba(216,102,45,0.35)]"
              >
                <div className="relative h-28 w-28 shrink-0 overflow-hidden rounded-[var(--radius-lg)]">
                  <Image
                    src={pickCardImage(item.image)}
                    alt={item.name}
                    fill
                    className="object-cover"
                    sizes="112px"
                  />
                  {!item.is_available ? (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/45">
                      <span className="text-xs font-semibold text-white uppercase">Sold out</span>
                    </div>
                  ) : null}
                </div>
                <div className="flex min-w-0 flex-1 flex-col justify-between">
                  <div className="flex items-start justify-between gap-3">
                    <h3 className="font-[family-name:var(--font-display)] text-xl">{item.name}</h3>
                    <p className="font-semibold text-[var(--color-burnt-orange)]">
                      {formatCents(item.base_price_cents)}
                    </p>
                  </div>
                  {item.short_description ? (
                    <p className="mt-1 line-clamp-2 text-sm text-[var(--text-secondary)]">
                      {item.short_description}
                    </p>
                  ) : null}
                </div>
              </button>
            ))}
          </div>
        ) : null}

        <p className="mt-8 text-center text-sm text-[var(--text-muted)]">
          <Link href={`/businesses/${business.slug}`} className="text-[var(--color-burnt-orange)]">
            Back to all locations
          </Link>
        </p>
      </section>

      <Modal open={Boolean(selected)} onClose={() => setSelected(null)} title={selected?.name ?? ""}>
        {selected ? (
          <div className="space-y-4">
            {!branch.accepting_orders ? (
              <p className="rounded-md bg-[rgba(180,120,40,0.12)] px-3 py-2 text-sm text-[var(--text-secondary)]">
                This location is not accepting orders right now. You can still browse the menu.
              </p>
            ) : null}
            {selected.variants.length > 0 ? (
              <div className="space-y-2">
                <p className="text-sm font-medium">Options</p>
                {selected.variants.map((v) => (
                  <label key={v.public_id} className="flex items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="variant"
                      checked={variantId === v.public_id}
                      onChange={() => setVariantId(v.public_id)}
                    />
                    {v.name} · {formatCents(v.price_cents)}
                  </label>
                ))}
              </div>
            ) : null}
            <Textarea
              value={instructions}
              onChange={(e) => setInstructions(e.target.value)}
              placeholder="Special instructions"
            />
            <div className="flex items-center justify-between gap-3">
              <input
                type="number"
                min={1}
                value={qty}
                onChange={(e) => setQty(Math.max(1, Number(e.target.value) || 1))}
                className="w-20 rounded-md border px-2 py-1"
              />
              <Button
                type="button"
                disabled={!selected.is_available || !branch.accepting_orders}
                onClick={() => void onAddToCart()}
              >
                {!isAuthenticated
                  ? "Sign in to order"
                  : !branch.accepting_orders
                    ? "Ordering unavailable"
                    : "Add to cart"}
              </Button>
            </div>
          </div>
        ) : null}
      </Modal>
    </main>
  );
}
