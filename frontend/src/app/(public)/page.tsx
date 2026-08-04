"use client";

import Image from "next/image";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { SearchInput } from "@/components/ui/forms";
import {
  OfferCard,
  RestaurantCard,
} from "@/components/marketplace/cards";
import {
  cuisines,
  offers,
  restaurants,
  testimonials,
} from "@/data/mock";
import { SuvakamanaMenuSection } from "@/features/public-restaurants/components/suvakamana-menu-section";

export default function HomePage() {
  const featured = restaurants.filter((r) => r.isFeatured);

  return (
    <main>
      <section className="relative isolate min-h-[88vh] overflow-hidden text-white">
        <Image
          src="https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=1600&q=75"
          alt="Candlelit restaurant table with plated seasonal dishes"
          fill
          priority
          quality={75}
          className="object-cover"
          sizes="100vw"
        />
        <div className="absolute inset-0 bg-gradient-to-r from-black/80 via-black/55 to-black/25" />
        <div className="texture-grain absolute inset-0" />
        <div className="relative mx-auto flex min-h-[88vh] max-w-6xl flex-col justify-center px-4 py-20 sm:px-6">
          <p className="hero-fade font-[family-name:var(--font-display)] text-4xl sm:text-5xl">
            Khana
          </p>
          <h1 className="hero-fade hero-fade-delay-1 mt-6 max-w-3xl text-[var(--text-hero)] leading-[1.08] text-white">
            Restaurants, bakeries, butcheries &amp; groceries — one marketplace.
          </h1>
          <p className="hero-fade hero-fade-delay-2 mt-5 max-w-xl text-lg text-white/80">
            Order from local restaurants, bakeries, butcheries and groceries on Khana.
          </p>
          <form
            className="hero-fade hero-fade-delay-3 mt-8 flex w-full max-w-xl flex-col gap-3 sm:flex-row"
            action="/restaurants"
          >
            <SearchInput
              name="q"
              placeholder="Enter your area or postcode"
              className="flex-1"
              aria-label="Search by location"
            />
            <Button type="submit" size="lg" className="sm:w-auto">
              Browse partners
            </Button>
          </form>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div className="flex items-end justify-between gap-4">
          <div>
            <p className="text-xs font-semibold tracking-[0.18em] text-[var(--color-burnt-orange)] uppercase">
              Featured
            </p>
            <h2 className="mt-2 text-3xl text-[var(--text-primary)] sm:text-4xl">
              Restaurants worth a special evening
            </h2>
          </div>
          <Link
            href="/restaurants"
            className="hidden text-sm font-semibold text-[var(--color-burnt-orange)] sm:inline"
          >
            View all
          </Link>
        </div>
        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {featured.map((restaurant) => (
            <RestaurantCard key={restaurant.id} restaurant={restaurant} />
          ))}
        </div>
      </section>

      <section className="border-y border-[var(--border-subtle)] bg-[var(--surface-muted)] py-16">
        <div className="mx-auto max-w-6xl px-4 sm:px-6">
          <h2 className="text-3xl text-[var(--text-primary)]">Cuisine moods</h2>
          <div className="mt-6 flex gap-3 overflow-x-auto pb-2">
            {cuisines.map((cuisine) => (
              <Link
                key={cuisine}
                href={`/restaurants?cuisine=${encodeURIComponent(cuisine)}`}
                className="shrink-0 rounded-[var(--radius-pill)] border border-[var(--border-strong)] bg-white px-4 py-2 text-sm font-medium text-[var(--text-primary)] transition hover:border-[var(--color-copper)] hover:text-[var(--color-copper)]"
              >
                {cuisine}
              </Link>
            ))}
          </div>
        </div>
      </section>

      <SuvakamanaMenuSection />

      <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <h2 className="text-3xl text-[var(--text-primary)]">Current offers</h2>
        <div className="mt-8 grid gap-6 lg:grid-cols-3">
          {offers.map((offer) => (
            <OfferCard key={offer.id} offer={offer} />
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div className="rounded-[var(--radius-xl)] bg-[var(--color-warm-black)] px-6 py-12 text-white sm:px-10">
          <h2 className="text-3xl sm:text-4xl">How Khana works</h2>
          <ol className="mt-8 grid gap-6 md:grid-cols-3">
            {[
              {
                step: "01",
                title: "Choose a partner",
                body: "Browse restaurants, bakeries, butcheries and groceries on Khana.",
              },
              {
                step: "02",
                title: "Build your order",
                body: "Select items, modifiers and delivery or pickup in one cart.",
              },
              {
                step: "03",
                title: "Track and enjoy",
                body: "Follow preparation live and receive your order when it is ready.",
              },
            ].map((item) => (
              <li key={item.step}>
                <p className="text-sm tracking-[0.2em] text-[var(--color-warm-gold)]">
                  {item.step}
                </p>
                <h3 className="mt-2 text-2xl">{item.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-white/70">{item.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <div className="grid items-center gap-8 overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] lg:grid-cols-2">
          <div className="p-8 sm:p-10">
            <p className="text-xs font-semibold tracking-[0.18em] text-[var(--color-burnt-orange)] uppercase">
              Restaurant partners
            </p>
            <h2 className="mt-3 text-3xl sm:text-4xl">
              Grow with a marketplace built for independents
            </h2>
            <p className="mt-4 text-[var(--text-secondary)]">
              Transparent commissions, polished menus and operational tools that keep
              service calm during peak hours.
            </p>
            <Link href="/partner/apply" className="mt-6 inline-block">
              <Button>Become a partner</Button>
            </Link>
          </div>
          <div className="relative min-h-64">
            <Image
              src="https://images.unsplash.com/photo-1559339352-11d035aa65de?auto=format&fit=crop&w=1200&q=80"
              alt="Chef plating a dish in a professional kitchen"
              fill
              className="object-cover"
              sizes="(max-width:1024px) 100vw, 50vw"
            />
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-3xl">Stories from the table</h2>
        <div className="mt-8 grid gap-6 md:grid-cols-3">
          {testimonials.map((item) => (
            <blockquote
              key={item.name}
              className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-6 shadow-[var(--shadow-sm)]"
            >
              <p className="text-[var(--text-secondary)]">&ldquo;{item.quote}&rdquo;</p>
              <footer className="mt-4">
                <p className="font-semibold">{item.name}</p>
                <p className="text-xs text-[var(--text-muted)]">{item.role}</p>
              </footer>
            </blockquote>
          ))}
        </div>
      </section>

      <section className="border-t border-[var(--border-subtle)] bg-[var(--surface-muted)] py-16">
        <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
          <div>
            <h2 className="text-3xl">Stay close to new flavours</h2>
            <p className="mt-2 max-w-md text-sm text-[var(--text-secondary)]">
              Occasional notes on featured kitchens, seasonal menus and partner openings.
            </p>
          </div>
          <form className="flex w-full max-w-md flex-col gap-3 sm:flex-row">
            <SearchInput
              type="email"
              placeholder="Email address"
              aria-label="Email for newsletter"
              className="flex-1"
            />
            <Button type="button">Subscribe</Button>
          </form>
        </div>
      </section>
    </main>
  );
}
