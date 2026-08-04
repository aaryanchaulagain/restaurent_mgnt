"use client";

import { useState } from "react";
import { Breadcrumbs, useToast } from "@/components/ui/navigation";
import { Field, Input, Textarea } from "@/components/ui/forms";
import { Button } from "@/components/ui/button";

const SUVAKAMANA_ADDRESS = "42 George Street, Sydney NSW 2000, Australia";
const MAP_QUERY = encodeURIComponent(`Suvakamana Restaurant, ${SUVAKAMANA_ADDRESS}`);
const MAP_EMBED_SRC = `https://maps.google.com/maps?q=${MAP_QUERY}&z=15&output=embed`;
const MAP_OPEN_URL = `https://www.google.com/maps/search/?api=1&query=${MAP_QUERY}`;

export default function ContactPage() {
  const { push } = useToast();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");

  return (
    <main className="mx-auto max-w-5xl px-4 py-10 sm:px-6">
      <Breadcrumbs items={[{ label: "Home", href: "/" }, { label: "Contact" }]} />
      <h1 className="mt-4 font-[family-name:var(--font-display)] text-4xl text-[var(--text-primary)] sm:text-5xl">
        Contact
      </h1>
      <p className="mt-3 max-w-2xl text-[var(--text-secondary)]">
        Questions about orders, partnerships, or the Khana platform? Send us a message.
      </p>

      <div className="mt-8 grid gap-8 lg:grid-cols-2">
        <div className="space-y-4 text-sm text-[var(--text-secondary)]">
          <div>
            <p className="font-semibold text-[var(--text-primary)]">Khana support</p>
            <a
              href="mailto:support@khana.local"
              className="text-[var(--color-burnt-orange)] hover:underline"
            >
              support@khana.local
            </a>
          </div>
          <div>
            <p className="font-semibold text-[var(--text-primary)]">Partner enquiries</p>
            <a
              href="mailto:partners@khana.local"
              className="text-[var(--color-burnt-orange)] hover:underline"
            >
              partners@khana.local
            </a>
          </div>
          <div>
            <p className="font-semibold text-[var(--text-primary)]">Featured partner location</p>
            <p className="mt-1">Suvakamana Restaurant</p>
            <p>{SUVAKAMANA_ADDRESS}</p>
            <a
              href={MAP_OPEN_URL}
              target="_blank"
              rel="noreferrer"
              className="mt-2 inline-block text-[var(--color-burnt-orange)] hover:underline"
            >
              Open in Google Maps
            </a>
          </div>
          <div>
            <p className="font-semibold text-[var(--text-primary)]">Hours</p>
            <p>Support · Mon–Sun, 9am–9pm</p>
          </div>
        </div>

        <form
          className="space-y-4 rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]"
          onSubmit={(e) => {
            e.preventDefault();
            push({
              title: "Message ready",
              description:
                "Contact form UI is live. Wire email delivery when you need live submissions.",
              tone: "info",
            });
            setName("");
            setEmail("");
            setMessage("");
          }}
        >
          <Field label="Name" htmlFor="contact-name">
            <Input
              id="contact-name"
              name="name"
              required
              placeholder="Your name"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </Field>
          <Field label="Email" htmlFor="contact-email">
            <Input
              id="contact-email"
              name="email"
              type="email"
              required
              placeholder="you@example.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </Field>
          <Field label="Message" htmlFor="contact-message">
            <Textarea
              id="contact-message"
              name="message"
              required
              placeholder="How can we help?"
              rows={5}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
            />
          </Field>
          <Button type="submit" className="w-full">
            Send message
          </Button>
        </form>
      </div>

      <section className="mt-12">
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-xs font-semibold tracking-[0.18em] text-[var(--color-burnt-orange)] uppercase">
              Find us
            </p>
            <h2 className="mt-1 font-[family-name:var(--font-display)] text-3xl text-[var(--text-primary)]">
              Suvakamana Restaurant · Sydney
            </h2>
            <p className="mt-1 text-sm text-[var(--text-secondary)]">{SUVAKAMANA_ADDRESS}</p>
          </div>
          <a
            href={MAP_OPEN_URL}
            target="_blank"
            rel="noreferrer"
            className="text-sm font-semibold text-[var(--color-burnt-orange)] hover:underline"
          >
            Directions
          </a>
        </div>
        <div className="overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border-subtle)] shadow-[var(--shadow-md)]">
          <iframe
            title="Suvakamana Restaurant on Google Maps — Sydney, Australia"
            src={MAP_EMBED_SRC}
            className="h-[360px] w-full border-0 sm:h-[440px]"
            loading="lazy"
            referrerPolicy="no-referrer-when-downgrade"
            allowFullScreen
          />
        </div>
      </section>
    </main>
  );
}
