"use client";

import Image from "next/image";
import Link from "next/link";
import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Badge, EmptyState, Skeleton } from "@/components/ui/feedback";
import { Button } from "@/components/ui/button";
import { Breadcrumbs, useToast } from "@/components/ui/navigation";
import { Modal } from "@/components/ui/overlay";
import { SearchInput, Textarea } from "@/components/ui/forms";
import { publicRestaurantApi, type PublicMenuItem } from "@/features/cart/api/cart-api";
import { useCart } from "@/features/cart/components/cart-provider";
import { formatCents } from "@/lib/utils";
import { pickCardImage } from "@/lib/media";

function pickImage(urls: { card_url: string; original_url: string }) {
  return pickCardImage(urls);
}

function MenuItemRow({
  item,
  onOpen,
}: {
  item: PublicMenuItem;
  onOpen: (item: PublicMenuItem) => void;
}) {
  return (
    <motion.button
      type="button"
      onClick={() => onOpen(item)}
      initial={{ opacity: 0, y: 10 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, margin: "-40px" }}
      transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
      whileHover={{ y: -2 }}
      className="group flex w-full gap-4 overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-3 text-left shadow-[var(--shadow-sm)] transition duration-300 hover:border-[rgba(216,102,45,0.35)] hover:shadow-[var(--shadow-md)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-burnt-orange)] sm:gap-5 sm:p-4"
    >
      <div className="relative h-28 w-28 shrink-0 overflow-hidden rounded-[var(--radius-lg)] sm:h-32 sm:w-36">
        <Image
          src={pickImage(item.image)}
          alt={item.name}
          fill
          className="object-cover transition duration-500 group-hover:scale-105"
          sizes="144px"
        />
        {!item.is_available ? (
          <div className="absolute inset-0 flex items-center justify-center bg-black/45">
            <span className="text-xs font-semibold tracking-wide text-white uppercase">Sold out</span>
          </div>
        ) : null}
      </div>
      <div className="flex min-w-0 flex-1 flex-col justify-between py-0.5">
        <div>
          <div className="flex items-start justify-between gap-3">
            <h3 className="font-[family-name:var(--font-display)] text-xl text-[var(--text-primary)] sm:text-2xl">
              {item.name}
            </h3>
            <p className="shrink-0 text-base font-semibold text-[var(--color-burnt-orange)]">
              {formatCents(item.base_price_cents)}
            </p>
          </div>
          {item.short_description ? (
            <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-[var(--text-secondary)]">
              {item.short_description}
            </p>
          ) : null}
          <div className="mt-2 flex flex-wrap gap-1.5">
            {item.dietary.is_vegetarian ? <Badge tone="success">Vegetarian</Badge> : null}
            {item.dietary.is_vegan ? <Badge tone="success">Vegan</Badge> : null}
            {item.dietary.is_gluten_free ? <Badge tone="info">GF</Badge> : null}
            {item.dietary.is_halal ? <Badge tone="info">Halal</Badge> : null}
          </div>
        </div>
        <div className="mt-3 flex items-center justify-between gap-3">
          <span className="text-xs text-[var(--text-muted)]">
            {item.is_available ? "Tap to customise & add" : item.availability_message ?? "Unavailable"}
          </span>
          <span className="rounded-[var(--radius-md)] bg-[rgba(216,102,45,0.1)] px-3 py-1.5 text-xs font-semibold text-[var(--color-burnt-orange)] transition group-hover:bg-[var(--color-burnt-orange)] group-hover:text-white">
            {item.is_available ? "Add" : "View"}
          </span>
        </div>
      </div>
    </motion.button>
  );
}

export function LiveRestaurantPage({ slug }: { slug: string }) {
  const { push } = useToast();
  const { cart, pricing, addItem } = useCart();
  const [menuQuery, setMenuQuery] = useState("");
  const [activeCategory, setActiveCategory] = useState<string | "all">("all");
  const [selected, setSelected] = useState<PublicMenuItem | null>(null);
  const [variantId, setVariantId] = useState<string | null>(null);
  const [modifierIds, setModifierIds] = useState<string[]>([]);
  const [qty, setQty] = useState(1);
  const [instructions, setInstructions] = useState("");

  const restaurantQuery = useQuery({
    queryKey: ["public-restaurant", slug],
    queryFn: async () => (await publicRestaurantApi.getRestaurant(slug)).data,
    retry: false,
  });

  const menuQueryResult = useQuery({
    queryKey: ["public-menu", slug],
    queryFn: async () => (await publicRestaurantApi.getMenu(slug)).data,
    enabled: Boolean(restaurantQuery.data),
    retry: false,
  });

  const categories = menuQueryResult.data?.categories ?? [];
  const items = useMemo(() => {
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
  }, [menuQueryResult.data, menuQuery, activeCategory]);

  const openItem = (item: PublicMenuItem) => {
    setSelected(item);
    setQty(1);
    setInstructions("");
    const defaultVariant = item.variants.find((v) => v.is_default) ?? item.variants[0];
    setVariantId(defaultVariant?.public_id ?? null);
    const defaults = item.modifier_groups.flatMap((g) =>
      g.options.filter((o) => o.is_default).map((o) => o.public_id),
    );
    setModifierIds(defaults);
  };

  const onAddToCart = async () => {
    if (!selected || !selected.is_available) return;
    try {
      await addItem({
        menu_item_public_id: selected.public_id,
        variant_public_id: variantId ?? undefined,
        quantity: qty,
        modifier_option_public_ids: modifierIds,
        special_instructions: instructions || undefined,
      });
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

  if (restaurantQuery.isLoading) {
    return (
      <main className="min-h-screen bg-[var(--surface-page)]">
        <Skeleton className="h-[70vh] w-full rounded-none" />
      </main>
    );
  }

  if (restaurantQuery.isError || !restaurantQuery.data?.restaurant) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-20">
        <EmptyState
          title="Restaurant not found"
          description="This restaurant is unavailable or the link is incorrect."
        />
      </main>
    );
  }

  const r = restaurantQuery.data.restaurant;
  const unavailable = !r.accepting_orders;
  const openLabel = unavailable
    ? "Not accepting orders"
    : r.is_open
      ? "Open now"
      : "Closed";
  const locationLine = r.address_summary
    ? `${r.address_summary.suburb}, ${r.address_summary.state}`
    : r.short_description;
  const cuisineLine = r.cuisines?.map((c) => c.name).filter(Boolean).join(" · ");

  const estimateUnit = () => {
    if (!selected) return 0;
    const variant = selected.variants.find((v) => v.public_id === variantId);
    const base = variant?.price_cents ?? selected.base_price_cents;
    const mods = selected.modifier_groups
      .flatMap((g) => g.options)
      .filter((o) => modifierIds.includes(o.public_id))
      .reduce((s, o) => s + o.price_adjustment_cents, 0);
    return (base + mods) * qty;
  };

  const scrollToMenu = () => {
    document.getElementById("menu")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const sections =
    activeCategory === "all"
      ? categories
          .map((cat) => ({
            cat,
            catItems: items.filter((i) => i.menu_category_public_id === cat.public_id),
          }))
          .filter((s) => s.catItems.length > 0)
      : [
          {
            cat: categories.find((c) => c.public_id === activeCategory) ?? {
              public_id: activeCategory,
              name: "Menu",
            },
            catItems: items,
          },
        ];

  const known = new Set(categories.map((c) => c.public_id));
  const orphans =
    activeCategory === "all"
      ? items.filter((i) => !i.menu_category_public_id || !known.has(i.menu_category_public_id))
      : [];

  return (
    <main className="relative min-h-screen bg-[var(--surface-page)]">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 top-[70vh] h-[40rem] bg-[radial-gradient(ellipse_at_top,rgba(216,102,45,0.08),transparent_55%)]"
      />

      <section className="relative isolate min-h-[78vh] overflow-hidden text-white">
        <Image
          src={pickImage(r.cover)}
          alt={`${r.trading_name} dining atmosphere`}
          fill
          priority
          className="object-cover"
          sizes="100vw"
        />
        <div className="absolute inset-0 bg-gradient-to-r from-black/80 via-black/55 to-black/25" />
        <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-black/20" />
        <div className="texture-grain absolute inset-0" />

        <div className="relative mx-auto flex min-h-[78vh] max-w-6xl flex-col justify-end px-4 pb-14 pt-28 sm:px-6 sm:pb-16">
          <Breadcrumbs
            items={[
              { label: "Home", href: "/" },
              { label: "Restaurants", href: "/restaurants" },
              { label: r.trading_name },
            ]}
            className="text-white/70 [&_a]:text-white/80 [&_a:hover]:text-white"
          />

          <motion.p
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.45 }}
            className="mt-6 font-[family-name:var(--font-display)] text-5xl leading-[1.05] sm:text-6xl md:text-7xl"
          >
            {r.trading_name}
          </motion.p>

          <motion.p
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.08 }}
            className="mt-4 max-w-xl text-base text-white/80 sm:text-lg"
          >
            {r.short_description ||
              cuisineLine ||
              locationLine ||
              "Crafted dishes, ready for pickup or delivery."}
          </motion.p>

          <motion.div
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.16 }}
            className="mt-8 flex flex-wrap items-center gap-3"
          >
            <Button size="lg" onClick={scrollToMenu}>
              Browse menu
            </Button>
            <span
              className={`rounded-[var(--radius-md)] px-3 py-2 text-sm font-medium backdrop-blur-sm ${
                r.is_open && !unavailable
                  ? "bg-[rgba(47,107,79,0.35)] text-[#d8f3e4]"
                  : "bg-white/15 text-white/90"
              }`}
            >
              {openLabel}
            </span>
            {r.minimum_order_cents ? (
              <span className="rounded-[var(--radius-md)] bg-white/12 px-3 py-2 text-sm text-white/85 backdrop-blur-sm">
                Min {formatCents(r.minimum_order_cents)}
              </span>
            ) : null}
            {r.average_preparation_minutes ? (
              <span className="rounded-[var(--radius-md)] bg-white/12 px-3 py-2 text-sm text-white/85 backdrop-blur-sm">
                ~{r.average_preparation_minutes} min
              </span>
            ) : null}
          </motion.div>
        </div>
      </section>

      <div id="menu" className="relative mx-auto max-w-6xl px-4 sm:px-6">
        {(locationLine || cuisineLine || r.description) && (
          <div className="mt-10 max-w-3xl">
            {cuisineLine || locationLine ? (
              <p className="text-sm font-medium tracking-wide text-[var(--color-copper)] uppercase">
                {[cuisineLine, locationLine].filter(Boolean).join(" · ")}
              </p>
            ) : null}
            {r.description ? (
              <p className="mt-3 text-base leading-relaxed text-[var(--text-secondary)]">{r.description}</p>
            ) : null}
          </div>
        )}

        {menuQueryResult.isError ? (
          <p className="mt-10 text-sm text-red-600">Menu is temporarily unavailable.</p>
        ) : menuQueryResult.isLoading ? (
          <div className="mt-10 space-y-4">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-36 w-full" />
            <Skeleton className="h-36 w-full" />
          </div>
        ) : (menuQueryResult.data?.items?.length ?? 0) === 0 ? (
          <div className="mt-16">
            <EmptyState
              title="No menu items"
              description="This restaurant has not published menu items yet."
            />
          </div>
        ) : (
          <>
            <div className="sticky top-16 z-20 -mx-4 mt-10 border-y border-[var(--border-subtle)] bg-[var(--surface-glass)] px-4 py-4 backdrop-blur-md sm:mx-0 sm:rounded-[var(--radius-xl)] sm:border sm:shadow-[var(--shadow-sm)]">
              <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                  <button
                    type="button"
                    onClick={() => setActiveCategory("all")}
                    className={`shrink-0 rounded-[var(--radius-md)] px-3.5 py-2 text-sm font-medium transition ${
                      activeCategory === "all"
                        ? "bg-[var(--color-charcoal)] text-white"
                        : "bg-white text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
                    }`}
                  >
                    All
                  </button>
                  {categories.map((cat) => (
                    <button
                      key={cat.public_id}
                      type="button"
                      onClick={() => setActiveCategory(cat.public_id)}
                      className={`shrink-0 rounded-[var(--radius-md)] px-3.5 py-2 text-sm font-medium transition ${
                        activeCategory === cat.public_id
                          ? "bg-[var(--color-charcoal)] text-white"
                          : "bg-white text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
                      }`}
                    >
                      {cat.name}
                    </button>
                  ))}
                </div>
                <SearchInput
                  value={menuQuery}
                  onChange={(e) => setMenuQuery(e.target.value)}
                  placeholder="Search dishes"
                  className="w-full lg:max-w-xs"
                />
              </div>
            </div>

            <div className="mt-10 space-y-14 pb-28">
              {sections.map(({ cat, catItems }) => (
                <section key={cat.public_id} id={cat.public_id}>
                  <div className="mb-5 flex items-end justify-between gap-4">
                    <div>
                      <p className="text-xs font-semibold tracking-[0.18em] text-[var(--color-burnt-orange)] uppercase">
                        Menu
                      </p>
                      <h2 className="mt-1 font-[family-name:var(--font-display)] text-3xl text-[var(--text-primary)] sm:text-4xl">
                        {cat.name}
                      </h2>
                    </div>
                    <p className="text-sm text-[var(--text-muted)]">{catItems.length} dishes</p>
                  </div>
                  <div className="grid gap-4">
                    {catItems.map((item) => (
                      <MenuItemRow key={item.public_id} item={item} onOpen={openItem} />
                    ))}
                  </div>
                </section>
              ))}

              {orphans.length > 0 ? (
                <section>
                  <h2 className="font-[family-name:var(--font-display)] text-3xl">More items</h2>
                  <div className="mt-5 grid gap-4">
                    {orphans.map((item) => (
                      <MenuItemRow key={item.public_id} item={item} onOpen={openItem} />
                    ))}
                  </div>
                </section>
              ) : null}

              {items.length === 0 ? (
                <EmptyState
                  title="No matching dishes"
                  description="Try another category or clear your search."
                />
              ) : null}
            </div>
          </>
        )}
      </div>

      {cart ? (
        <Link
          href="/cart"
          className="fixed bottom-6 left-1/2 z-30 flex -translate-x-1/2 items-center gap-4 rounded-[var(--radius-xl)] bg-[var(--color-charcoal)] px-5 py-3.5 text-white shadow-[var(--shadow-lg)] transition hover:scale-[1.02] sm:left-auto sm:right-6 sm:translate-x-0"
        >
          <span className="text-sm font-medium">{cart.restaurant.trading_name}</span>
          <span className="rounded-[var(--radius-md)] bg-[var(--color-burnt-orange)] px-2.5 py-1 text-sm font-semibold">
            {formatCents(pricing?.total_before_delivery_cents ?? 0)}
          </span>
        </Link>
      ) : null}

      <Modal
        open={Boolean(selected)}
        onClose={() => setSelected(null)}
        title={selected?.name ?? "Item"}
        className="sm:max-w-xl"
      >
        {selected ? (
          <div className="space-y-4">
            <div className="relative aspect-[16/9] overflow-hidden rounded-[var(--radius-lg)]">
              <Image
                src={pickImage(selected.image)}
                alt={selected.name}
                fill
                className="object-cover"
                sizes="(max-width:640px) 100vw, 560px"
              />
            </div>
            <p className="text-sm leading-relaxed text-[var(--text-secondary)]">
              {selected.description ?? selected.short_description}
            </p>
            {selected.variants.length > 0 ? (
              <div>
                <p className="mb-2 text-sm font-medium">Choose size</p>
                <div className="space-y-2">
                  {selected.variants.map((v) => (
                    <label
                      key={v.public_id}
                      className="flex cursor-pointer items-center gap-2 rounded-[var(--radius-md)] border border-[var(--border-subtle)] px-3 py-2.5 text-sm transition has-[:checked]:border-[var(--color-burnt-orange)] has-[:checked]:bg-[rgba(216,102,45,0.06)]"
                    >
                      <input
                        type="radio"
                        name="variant"
                        checked={variantId === v.public_id}
                        onChange={() => setVariantId(v.public_id)}
                      />
                      <span className="flex-1">{v.name}</span>
                      <span className="font-medium text-[var(--color-burnt-orange)]">
                        {formatCents(v.price_cents)}
                      </span>
                    </label>
                  ))}
                </div>
              </div>
            ) : null}
            {selected.modifier_groups.map((group) => (
              <div key={group.public_id}>
                <p className="mb-2 text-sm font-medium">
                  {group.name}
                  {group.is_required ? " *" : ""}
                </p>
                <div className="space-y-2">
                  {group.options.map((opt) => {
                    const checked = modifierIds.includes(opt.public_id);
                    return (
                      <label
                        key={opt.public_id}
                        className="flex cursor-pointer items-center gap-2 rounded-[var(--radius-md)] border border-[var(--border-subtle)] px-3 py-2.5 text-sm transition has-[:checked]:border-[var(--color-burnt-orange)] has-[:checked]:bg-[rgba(216,102,45,0.06)]"
                      >
                        <input
                          type={group.selection_type === "single" ? "radio" : "checkbox"}
                          name={group.public_id}
                          checked={checked}
                          onChange={() => {
                            if (group.selection_type === "single") {
                              const without = modifierIds.filter(
                                (id) => !group.options.some((o) => o.public_id === id),
                              );
                              setModifierIds([...without, opt.public_id]);
                            } else {
                              setModifierIds((ids) =>
                                checked ? ids.filter((id) => id !== opt.public_id) : [...ids, opt.public_id],
                              );
                            }
                          }}
                        />
                        <span className="flex-1">{opt.name}</span>
                        {opt.price_adjustment_cents ? (
                          <span className="text-[var(--text-muted)]">
                            +{formatCents(opt.price_adjustment_cents)}
                          </span>
                        ) : null}
                      </label>
                    );
                  })}
                </div>
              </div>
            ))}
            <div className="flex items-center gap-3">
              <span className="text-sm font-medium">Qty</span>
              <Button size="sm" variant="outline" onClick={() => setQty((q) => Math.max(1, q - 1))}>
                −
              </Button>
              <span className="w-8 text-center font-semibold">{qty}</span>
              <Button size="sm" variant="outline" onClick={() => setQty((q) => q + 1)}>
                +
              </Button>
            </div>
            <Textarea
              placeholder="Special instructions"
              value={instructions}
              onChange={(e) => setInstructions(e.target.value)}
              aria-label="Special instructions"
            />
            {selected.allergens.length ? (
              <p className="text-xs text-[var(--text-muted)]">
                Allergens: {selected.allergens.map((a) => a.name).join(", ")}.{" "}
                {restaurantQuery.data.allergen_disclaimer}
              </p>
            ) : null}
            <Button
              className="w-full"
              size="lg"
              disabled={!selected.is_available || unavailable}
              onClick={onAddToCart}
            >
              Add to cart · {formatCents(estimateUnit())}
            </Button>
          </div>
        ) : null}
      </Modal>
    </main>
  );
}
