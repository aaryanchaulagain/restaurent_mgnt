"use client";

import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { RestaurantCard } from "@/components/marketplace/cards";
import { Checkbox, SearchInput, Select } from "@/components/ui/forms";
import { EmptyState, Skeleton } from "@/components/ui/feedback";
import { Breadcrumbs } from "@/components/ui/navigation";
import { publicRestaurantApi } from "@/features/restaurant/api/restaurant-api";
import { cuisines as mockCuisines, restaurants as mockRestaurants } from "@/data/mock";
import type { Restaurant } from "@/data/mock";
import { MARKETPLACE_PLACEHOLDER_IMAGE, pickCardImage } from "@/lib/media";

function mapApiToCard(r: {
  slug: string;
  trading_name: string;
  short_description?: string | null;
  open_now?: boolean;
  is_open?: boolean;
  minimum_order_cents?: number;
  cuisines?: { name: string }[];
  is_platform_restaurant?: boolean;
  cover?: { card_url?: string; original_url?: string };
  logo?: { card_url?: string; original_url?: string };
  is_featured_partner?: boolean;
}): Restaurant {
  return {
    id: r.slug,
    slug: r.slug,
    name: r.trading_name,
    cuisine: r.cuisines?.[0]?.name ?? "Restaurant",
    description: r.short_description ?? "",
    address: r.short_description ?? "",
    image: pickCardImage(r.cover, MARKETPLACE_PLACEHOLDER_IMAGE),
    logo: pickCardImage(r.logo, MARKETPLACE_PLACEHOLDER_IMAGE),
    isOpen: Boolean(r.is_open ?? r.open_now),
    isFeatured: Boolean(r.is_platform_restaurant),
    rating: 4.5,
    reviewCount: 0,
    deliveryMinutes: 30,
    deliveryFeeCents: 499,
    minOrderCents: r.minimum_order_cents ?? 0,
    commissionRate: 0,
    offerLabel: undefined,
    isPlatformRestaurant: r.is_platform_restaurant ?? false,
    isFeaturedPartner: r.is_featured_partner ?? false,
  };
}

const demoFallbackEnabled = process.env.NEXT_PUBLIC_ENABLE_DEMO_FALLBACK === "true";

export default function RestaurantsPage() {
  const [query, setQuery] = useState("");
  const [cuisine, setCuisine] = useState("all");
  const [ownership, setOwnership] = useState("all");
  const [openOnly, setOpenOnly] = useState(false);
  const [useMockFallback, setUseMockFallback] = useState(false);

  const cuisinesQuery = useQuery({
    queryKey: ["public", "cuisines"],
    queryFn: async () => (await publicRestaurantApi.listCuisines()).data.cuisines,
    retry: false,
  });

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ["public", "restaurants", query, cuisine, ownership, openOnly],
    queryFn: async () => {
      const res = await publicRestaurantApi.listRestaurants({
        search: query || undefined,
        cuisine_slug: cuisine !== "all" ? cuisine : undefined,
        ownership_type:
          ownership === "first_party" || ownership === "third_party" ? ownership : undefined,
        open_now: openOnly ? "1" : undefined,
        per_page: "40",
      });
      return res.data.restaurants;
    },
    retry: false,
  });

  const restaurants = useMemo(() => {
    if (useMockFallback && demoFallbackEnabled) {
      return mockRestaurants;
    }
    if (isError && !data) {
      return [];
    }
    return (data ?? []).map((r) =>
      mapApiToCard({ ...r, cuisines: (r as { cuisines?: { name: string }[] }).cuisines }),
    );
  }, [data, isError, useMockFallback]);

  const filtered = useMemo(() => {
    let list = [...restaurants];
    if (useMockFallback && query.trim()) {
      const q = query.toLowerCase();
      list = list.filter(
        (r) =>
          r.name.toLowerCase().includes(q) ||
          r.cuisine.toLowerCase().includes(q) ||
          r.address.toLowerCase().includes(q),
      );
    }
    if (useMockFallback && cuisine !== "all") list = list.filter((r) => r.cuisine === cuisine);
    if (useMockFallback && ownership === "first_party") {
      list = list.filter((r) => r.isPlatformRestaurant);
    }
    if (useMockFallback && ownership === "third_party") {
      list = list.filter((r) => !r.isPlatformRestaurant);
    }
    if (useMockFallback && openOnly) list = list.filter((r) => r.isOpen);
    return list;
  }, [restaurants, query, cuisine, ownership, openOnly, useMockFallback]);

  const cuisineOptions = cuisinesQuery.data ?? [];

  return (
    <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Restaurants" }]} />
      {isError && !useMockFallback ? (
        <div className="mt-4 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-muted)] px-4 py-6 text-center">
          <p className="text-sm text-[var(--text-secondary)]">
            We couldn&apos;t load restaurants right now.
          </p>
          <button
            type="button"
            className="mt-3 text-sm font-medium text-[var(--color-burnt-orange)] underline"
            onClick={() => void refetch()}
          >
            {isFetching ? "Retrying…" : "Try again"}
          </button>
          {demoFallbackEnabled ? (
            <p className="mt-3 text-xs text-[var(--text-muted)]">
              <button type="button" className="underline" onClick={() => setUseMockFallback(true)}>
                View labelled demonstration listings
              </button>
            </p>
          ) : null}
        </div>
      ) : null}
      {useMockFallback && demoFallbackEnabled ? (
        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          Demonstration data — not live marketplace listings.
        </p>
      ) : null}
      <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-4xl text-[var(--text-primary)]">Restaurants</h1>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            {useMockFallback && demoFallbackEnabled
              ? "Demonstration listings (development only)"
              : "Browse restaurants, bakeries, butcheries and groceries on Khana."}
          </p>
        </div>
        <SearchInput
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search restaurants"
          className="w-full sm:max-w-xs"
        />
      </div>

      <div className="mt-8 grid gap-8 lg:grid-cols-[240px_1fr]">
        <aside className="space-y-5 rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-4 shadow-[var(--shadow-sm)]">
          <div>
            <p className="mb-2 text-xs font-semibold tracking-wider text-[var(--text-muted)] uppercase">
              Partner type
            </p>
            <Select value={ownership} onChange={(e) => setOwnership(e.target.value)}>
              <option value="all">All partners</option>
              <option value="first_party">Khana-operated</option>
              <option value="third_party">Partners</option>
            </Select>
          </div>
          <div>
            <p className="mb-2 text-xs font-semibold tracking-wider text-[var(--text-muted)] uppercase">
              Cuisine
            </p>
            <Select value={cuisine} onChange={(e) => setCuisine(e.target.value)}>
              <option value="all">All cuisines</option>
              {useMockFallback
                ? mockCuisines.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))
                : cuisineOptions.map((c) => (
                    <option key={c.slug} value={c.slug}>
                      {c.name}
                    </option>
                  ))}
            </Select>
          </div>
          <Checkbox
            label="Open now"
            checked={openOnly}
            onChange={(e) => setOpenOnly(e.target.checked)}
          />
        </aside>

        <section>
          {isLoading ? (
            <div className="space-y-4 py-8">
              <Skeleton className="h-40 w-full" />
              <Skeleton className="h-40 w-full" />
            </div>
          ) : filtered.length === 0 ? (
            <EmptyState
              title="No restaurants match"
              description="Try adjusting your filters, or wait until restaurants are activated by admin."
            />
          ) : (
            <div className="grid gap-6 sm:grid-cols-2">
              {filtered.map((restaurant) => (
                <RestaurantCard key={restaurant.id} restaurant={restaurant} />
              ))}
            </div>
          )}
        </section>
      </div>
    </main>
  );
}
