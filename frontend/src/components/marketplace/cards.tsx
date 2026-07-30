import Image from "next/image";
import Link from "next/link";
import { Badge } from "@/components/ui/feedback";
import type { MenuItem, Offer, Restaurant } from "@/data/mock";
import { formatCents, formatRating } from "@/lib/utils";
import { cn } from "@/lib/utils";

export function RestaurantCard({ restaurant }: { restaurant: Restaurant }) {
  return (
    <Link
      href={`/restaurants/${restaurant.slug}`}
      className="group block overflow-hidden rounded-[var(--radius-xl)] bg-[var(--surface-elevated)] shadow-[var(--shadow-md)] transition hover:-translate-y-0.5 hover:shadow-[var(--shadow-lg)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-burnt-orange)]"
    >
      <div className="relative aspect-[16/10] overflow-hidden">
        <Image
          src={restaurant.image}
          alt={`${restaurant.name} dining atmosphere`}
          fill
          className="object-cover transition duration-500 group-hover:scale-105"
          sizes="(max-width:768px) 100vw, 33vw"
        />
        <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/55 to-transparent" />
        <div className="absolute top-3 left-3 flex gap-2">
          <Badge tone={restaurant.isOpen ? "success" : "error"}>
            {restaurant.isOpen ? "Open" : "Closed"}
          </Badge>
          {restaurant.isPlatformRestaurant ? <Badge tone="accent">Official Suvakamana Restaurant</Badge> : null}
          {restaurant.offerLabel ? <Badge tone="accent">{restaurant.offerLabel}</Badge> : null}
        </div>
      </div>
      <div className="p-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="font-[family-name:var(--font-display)] text-xl text-[var(--text-primary)]">
              {restaurant.name}
            </h3>
            <p className="mt-1 text-sm text-[var(--text-secondary)]">{restaurant.cuisine}</p>
          </div>
          <div className="text-right text-sm">
            <p className="font-semibold text-[var(--text-primary)]">
              {formatRating(restaurant.rating)}
            </p>
            <p className="text-[var(--text-muted)]">({restaurant.reviewCount})</p>
          </div>
        </div>
        <p className="mt-3 text-sm text-[var(--text-muted)]">
          {restaurant.deliveryMinutes} min · {formatCents(restaurant.deliveryFeeCents)} delivery · Min{" "}
          {formatCents(restaurant.minOrderCents)}
        </p>
      </div>
    </Link>
  );
}

export function FoodCard({
  item,
  onSelect,
}: {
  item: MenuItem;
  onSelect?: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className="group flex w-full overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] text-left shadow-[var(--shadow-sm)] transition hover:shadow-[var(--shadow-md)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-burnt-orange)]"
    >
      <div className="relative h-28 w-28 shrink-0 sm:h-32 sm:w-36">
        <Image
          src={item.image}
          alt={item.name}
          fill
          className="object-cover transition duration-500 group-hover:scale-105"
          sizes="144px"
        />
      </div>
      <div className="flex flex-1 flex-col justify-between p-3 sm:p-4">
        <div>
          <div className="flex items-start justify-between gap-2">
            <h3 className="font-semibold text-[var(--text-primary)]">{item.name}</h3>
            <span className="shrink-0 text-sm font-semibold text-[var(--color-burnt-orange)]">
              {formatCents(item.priceCents)}
            </span>
          </div>
          <p className="mt-1 line-clamp-2 text-sm text-[var(--text-secondary)]">
            {item.description}
          </p>
        </div>
        <div className="mt-2 flex flex-wrap gap-2">
          {item.isVeg ? <Badge tone="success">Vegetarian</Badge> : null}
          {item.popular ? <Badge tone="accent">Popular</Badge> : null}
        </div>
      </div>
    </button>
  );
}

export function OfferCard({ offer }: { offer: Offer }) {
  return (
    <article className="relative overflow-hidden rounded-[var(--radius-xl)] shadow-[var(--shadow-md)]">
      <div className="relative aspect-[5/3]">
        <Image
          src={offer.image}
          alt={offer.title}
          fill
          className="object-cover"
          sizes="(max-width:768px) 100vw, 33vw"
        />
        <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-transparent" />
      </div>
      <div className="absolute inset-x-0 bottom-0 p-5 text-white">
        <Badge tone="accent" className="mb-2 bg-white/15 text-white">
          {offer.badge}
        </Badge>
        <h3 className="font-[family-name:var(--font-display)] text-2xl">{offer.title}</h3>
        <p className="mt-1 text-sm text-white/85">{offer.description}</p>
        <p className="mt-2 text-xs uppercase tracking-wider text-[var(--color-warm-gold)]">
          {offer.restaurantName}
        </p>
      </div>
    </article>
  );
}

export function StatCard({
  label,
  value,
  hint,
  className,
}: {
  label: string;
  value: string;
  hint?: string;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-5 shadow-[var(--shadow-sm)]",
        className,
      )}
    >
      <p className="text-xs font-semibold tracking-wider text-[var(--text-muted)] uppercase">
        {label}
      </p>
      <p className="mt-2 font-[family-name:var(--font-display)] text-3xl text-[var(--text-primary)]">
        {value}
      </p>
      {hint ? <p className="mt-1 text-sm text-[var(--text-secondary)]">{hint}</p> : null}
    </div>
  );
}
