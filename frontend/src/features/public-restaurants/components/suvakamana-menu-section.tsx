"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Badge, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/overlay";
import { useRouter } from "next/navigation";
import { useCart } from "@/features/cart/components/cart-provider";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { useToast } from "@/components/ui/navigation";
import { apiGet } from "@/lib/api/client";
import { formatCents } from "@/lib/utils";
import { pickCardImage } from "@/lib/media";

type PlatformItem = {
  public_id: string;
  name: string;
  slug: string;
  short_description?: string | null;
  base_price_cents: number;
  compare_at_price_cents?: number | null;
  is_available: boolean;
  is_featured?: boolean;
  image?: { card_url?: string; original_url?: string; thumbnail_url?: string };
  dietary?: { is_vegetarian?: boolean; is_vegan?: boolean; is_gluten_free?: boolean; is_halal?: boolean };
  variants: Array<{ public_id: string; name: string; price_cents: number; is_default: boolean }>;
  modifier_groups: Array<{
    public_id: string;
    name: string;
    selection_type: string;
    minimum_selections: number;
    maximum_selections: number;
    is_required: boolean;
    options: Array<{ public_id: string; name: string; price_adjustment_cents: number; is_default: boolean }>;
  }>;
};

type PlatformRestaurant = {
  public_id: string;
  slug: string;
  trading_name: string;
  is_open: boolean;
  accepting_orders: boolean;
  pickup_enabled: boolean;
  restaurant_delivery_enabled: boolean;
  is_platform_restaurant?: boolean;
};

type PlatformData = {
  restaurant: PlatformRestaurant;
  featured_items: PlatformItem[];
  active_offers: Array<{ public_id: string; name: string; offer_type: string; value: number }>;
};

export function SuvakamanaMenuSection() {
  const router = useRouter();
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { push } = useToast();
  const { addItem } = useCart();
  const [selected, setSelected] = useState<PlatformItem | null>(null);
  const [variantId, setVariantId] = useState<string | null>(null);
  const [modifierIds, setModifierIds] = useState<string[]>([]);
  const [qty, setQty] = useState(1);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["platform-restaurant"],
    queryFn: async () => (await apiGet<PlatformData>("/api/v1/public/platform-restaurant")).data,
    retry: 1,
  });

  const openItem = (item: PlatformItem) => {
    setSelected(item);
    setQty(1);
    const defaultVariant = item.variants.find((v) => v.is_default) ?? item.variants[0];
    setVariantId(defaultVariant?.public_id ?? null);
    setModifierIds(
      item.modifier_groups.flatMap((g) => g.options.filter((o) => o.is_default).map((o) => o.public_id)),
    );
  };

  const needsConfig = (item: PlatformItem) =>
    item.variants.length > 1 || item.modifier_groups.some((g) => g.is_required);

  const requireLogin = () => {
    const next = encodeURIComponent(
      typeof window !== "undefined" ? window.location.pathname + window.location.search : "/",
    );
    router.push(`/login?next=${next}`);
  };

  const handleAdd = async (item?: PlatformItem) => {
    const target = item ?? selected;
    if (!target || !target.is_available) return;
    if (authLoading) return;
    if (!isAuthenticated) {
      requireLogin();
      return;
    }
    try {
      const result = await addItem({
        menu_item_public_id: target.public_id,
        variant_public_id: variantId ?? undefined,
        quantity: qty,
        modifier_option_public_ids: modifierIds.length > 0 ? modifierIds : undefined,
      });
      if (!result.ok) return;
      push({ title: "Added to cart", description: `${target.name} × ${qty}`, tone: "success" });
      setSelected(null);
    } catch (e) {
      push({ title: "Could not add item", description: e instanceof Error ? e.message : "Try again", tone: "error" });
    }
  };

  const directAdd = async (item: PlatformItem) => {
    if (needsConfig(item)) {
      openItem(item);
    } else {
      setVariantId(item.variants.find((v) => v.is_default)?.public_id ?? null);
      setModifierIds([]);
      setQty(1);
      await handleAdd(item);
    }
  };

  if (isLoading) {
    return (
      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <Skeleton className="h-8 w-64 mb-6" />
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-48 rounded-lg" />)}
        </div>
      </section>
    );
  }

  if (isError || !data) {
    return (
      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-3xl text-[var(--text-primary)]">Order from Suvakamana</h2>
        <p className="mt-3 text-[var(--text-secondary)]">Could not load partner menu. Please try again.</p>
        <Button className="mt-4" variant="outline" onClick={() => refetch()}>Retry</Button>
      </section>
    );
  }

  const { restaurant, featured_items, active_offers } = data;
  const isOpen = restaurant.is_open && restaurant.accepting_orders;

  return (
    <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
      <div className="flex flex-wrap items-center justify-between gap-4 mb-8">
        <div>
          <p className="text-xs font-semibold tracking-[0.18em] text-[var(--color-burnt-orange)] uppercase">
            Featured Khana partner
          </p>
          <h2 className="mt-2 text-3xl text-[var(--text-primary)]">{restaurant.trading_name}</h2>
          <div className="mt-2 flex flex-wrap gap-2">
            <Badge tone="accent">Suvakamana · Khana partner</Badge>
            <Badge tone={isOpen ? "success" : "error"}>{isOpen ? "Open now" : "Currently closed"}</Badge>
            {restaurant.pickup_enabled ? <Badge tone="info">Pickup</Badge> : null}
            {restaurant.restaurant_delivery_enabled ? <Badge tone="info">Delivery</Badge> : null}
          </div>
        </div>
        <Link href={`/restaurants/${restaurant.slug}`}>
          <Button variant="outline">View Full Menu</Button>
        </Link>
      </div>

      {active_offers.length > 0 ? (
        <div className="mb-6 flex flex-wrap gap-3">
          {active_offers.map((o) => (
            <div key={o.public_id} className="rounded-lg border border-[var(--color-burnt-orange)] bg-[rgba(216,102,45,0.06)] px-4 py-2">
              <p className="text-sm font-medium text-[var(--color-burnt-orange)]">
                {o.name} — {o.offer_type === "percentage" ? `${o.value}% off` : `${formatCents(Number(o.value))} off`}
              </p>
            </div>
          ))}
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {featured_items.map((item) => (
          <div key={item.public_id} className="group overflow-hidden rounded-xl border border-[var(--border-subtle)] bg-white shadow-sm transition hover:shadow-md">
            <div className="relative aspect-[4/3] overflow-hidden bg-[var(--surface-muted)]">
              <Image
                src={pickCardImage(item.image)}
                alt={item.name}
                fill
                className="object-cover transition duration-500 group-hover:scale-105"
                sizes="(max-width:768px) 100vw, 25vw"
              />
            </div>
            <div className="p-4">
              <h3 className="font-semibold text-[var(--text-primary)]">{item.name}</h3>
              {item.short_description ? (
                <p className="mt-1 line-clamp-2 text-sm text-[var(--text-secondary)]">{item.short_description}</p>
              ) : null}
              <div className="mt-2 flex items-center justify-between gap-2">
                <span className="font-semibold text-[var(--color-burnt-orange)]">{formatCents(item.base_price_cents)}</span>
                <div className="flex gap-1">
                  {item.dietary?.is_vegetarian ? <Badge tone="success">V</Badge> : null}
                  {item.dietary?.is_vegan ? <Badge tone="success">VG</Badge> : null}
                  {item.dietary?.is_gluten_free ? <Badge tone="info">GF</Badge> : null}
                </div>
              </div>
              <div className="mt-3">
                {!isOpen ? (
                  <Button size="sm" disabled className="w-full">Currently Closed</Button>
                ) : !item.is_available ? (
                  <Button size="sm" disabled className="w-full">Sold Out</Button>
                ) : needsConfig(item) ? (
                  <Button size="sm" variant="outline" className="w-full" onClick={() => openItem(item)}>Customise</Button>
                ) : (
                  <Button size="sm" className="w-full" onClick={() => directAdd(item)}>Add to Cart</Button>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>

      {selected ? (
        <Modal open onClose={() => setSelected(null)} title={selected.name}>
          {selected.short_description ? (
            <p className="text-sm text-[var(--text-secondary)] mb-4">{selected.short_description}</p>
          ) : null}

          {selected.variants.length > 1 ? (
            <div className="mb-4">
              <p className="text-sm font-medium mb-2">Select size</p>
              {selected.variants.map((v) => (
                <label key={v.public_id} className="flex items-center gap-2 py-1">
                  <input type="radio" name="variant" checked={variantId === v.public_id} onChange={() => setVariantId(v.public_id)} />
                  <span className="text-sm">{v.name} — {formatCents(v.price_cents)}</span>
                </label>
              ))}
            </div>
          ) : null}

          {selected.modifier_groups.map((g) => (
            <div key={g.public_id} className="mb-4">
              <p className="text-sm font-medium mb-1">{g.name} {g.is_required ? <span className="text-red-500">*</span> : null}</p>
              <p className="text-xs text-[var(--text-muted)] mb-2">
                {g.selection_type === "single" ? "Choose one" : `Choose ${g.minimum_selections}–${g.maximum_selections}`}
              </p>
              {g.options.map((o) => (
                <label key={o.public_id} className="flex items-center gap-2 py-1">
                  {g.selection_type === "single" ? (
                    <input
                      type="radio"
                      name={`mod-${g.public_id}`}
                      checked={modifierIds.includes(o.public_id)}
                      onChange={() => {
                        setModifierIds((ids) => [
                          ...ids.filter((id) => !g.options.some((opt) => opt.public_id === id)),
                          o.public_id,
                        ]);
                      }}
                    />
                  ) : (
                    <input
                      type="checkbox"
                      checked={modifierIds.includes(o.public_id)}
                      onChange={() => setModifierIds((ids) =>
                        ids.includes(o.public_id) ? ids.filter((x) => x !== o.public_id) : [...ids, o.public_id]
                      )}
                    />
                  )}
                  <span className="text-sm">{o.name}{o.price_adjustment_cents ? ` (+${formatCents(o.price_adjustment_cents)})` : ""}</span>
                </label>
              ))}
            </div>
          ))}

          <div className="flex items-center gap-3 mt-4">
            <div className="flex items-center gap-2">
              <Button size="sm" variant="outline" onClick={() => setQty((q) => Math.max(1, q - 1))}>−</Button>
              <span className="w-8 text-center font-medium">{qty}</span>
              <Button size="sm" variant="outline" onClick={() => setQty((q) => q + 1)}>+</Button>
            </div>
            <Button className="flex-1" onClick={() => handleAdd()}>
              Add to Cart — {formatCents(
                (variantId
                  ? selected.variants.find((v) => v.public_id === variantId)?.price_cents ?? selected.base_price_cents
                  : selected.base_price_cents) * qty
              )}
            </Button>
          </div>
        </Modal>
      ) : null}
    </section>
  );
}
