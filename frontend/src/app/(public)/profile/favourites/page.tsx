"use client";

import Link from "next/link";
import { RestaurantCard } from "@/components/marketplace/cards";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/feedback";
import { Breadcrumbs } from "@/components/ui/navigation";
import { CustomerGuard } from "@/features/auth/guards/route-guard";
import { restaurants } from "@/data/mock";

export default function ProfileFavouritesPage() {
  const favourites = restaurants.filter((r) => r.isFeatured).slice(0, 3);

  return (
    <CustomerGuard>
      <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <Breadcrumbs
          items={[
            { label: "Home", href: "/" },
            { label: "Profile", href: "/profile" },
            { label: "Favourites" },
          ]}
        />
        <h1 className="mt-4 text-4xl">Favourites</h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          Restaurants you have saved for quick reordering.
        </p>

        {favourites.length === 0 ? (
          <div className="mt-8">
            <EmptyState
              title="No favourites yet"
              description="Tap the heart on a restaurant to save it here."
              action={
                <Link href="/restaurants">
                  <Button>Discover restaurants</Button>
                </Link>
              }
            />
          </div>
        ) : (
          <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {favourites.map((restaurant) => (
              <RestaurantCard key={restaurant.id} restaurant={restaurant} />
            ))}
          </div>
        )}
      </main>
    </CustomerGuard>
  );
}
