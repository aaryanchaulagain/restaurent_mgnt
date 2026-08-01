"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Checkbox,
  Field,
  Input,
  Radio,
  Select,
  Textarea,
} from "@/components/ui/forms";
import { Breadcrumbs } from "@/components/ui/navigation";
import { useCart } from "@/features/cart/components/cart-provider";
import { cartApi, type CartPricing } from "@/features/cart/api/cart-api";
import { apiGet } from "@/lib/api/client";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { PlaceOrderButton } from "@/features/orders/components/place-order-button";
import {
  PaymentMethodSelector,
  type CheckoutPaymentMethod,
} from "@/features/payments/components/PaymentMethodSelector";
import { formatCents } from "@/lib/utils";

type FulfilmentType = "pickup" | "restaurant_delivery" | "third_party_delivery";

type CustomerAddress = {
  public_id: string;
  label?: string | null;
  recipient_name: string;
  phone?: string | null;
  address_line_1: string;
  address_line_2?: string | null;
  suburb: string;
  state: string;
  postcode: string;
  delivery_instructions?: string | null;
  is_default: boolean;
};

type QuoteResult = {
  public_id: string;
  expires_at: string;
  fulfilment_type: FulfilmentType;
  pricing: CartPricing;
  warnings: CartPricing["warnings"];
};

const isDev = process.env.NODE_ENV === "development";

export function CheckoutPageClient() {
  const { cart, pricing, isLoading, refetch } = useCart();
  const { isAuthenticated } = useAuth();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [fulfilment, setFulfilment] = useState<FulfilmentType>("pickup");
  const [addressId, setAddressId] = useState<string>("new");
  const [addresses, setAddresses] = useState<CustomerAddress[]>([]);
  const [line1, setLine1] = useState("");
  const [suburb, setSuburb] = useState("");
  const [state, setState] = useState("");
  const [postcode, setPostcode] = useState("");
  const [deliveryInstructions, setDeliveryInstructions] = useState("");
  const [pickupInstructions, setPickupInstructions] = useState("");
  const [contactless, setContactless] = useState(false);
  const [orderNotes, setOrderNotes] = useState("");
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [paymentMethod, setPaymentMethod] = useState<CheckoutPaymentMethod>("cash");
  const [quote, setQuote] = useState<QuoteResult | null>(null);
  const [quoteError, setQuoteError] = useState<string | null>(null);
  const [quoteLoading, setQuoteLoading] = useState(false);
  const [attempted, setAttempted] = useState(false);

  useEffect(() => {
    if (!isAuthenticated) return;
    void apiGet<{ addresses: CustomerAddress[] }>("/api/v1/customer/addresses")
      .then((res) => {
        setAddresses(res.data.addresses);
        const def = res.data.addresses.find((a) => a.is_default) ?? res.data.addresses[0];
        if (def) setAddressId(def.public_id);
      })
      .catch(() => undefined);
  }, [isAuthenticated]);

  const selectedAddress = useMemo(
    () => addresses.find((a) => a.public_id === addressId),
    [addresses, addressId],
  );

  const addressPayload = useCallback(() => {
    if (fulfilment !== "restaurant_delivery") return undefined;
    if (selectedAddress && addressId !== "new") {
      return {
        address_line_1: selectedAddress.address_line_1,
        suburb: selectedAddress.suburb,
        state: selectedAddress.state,
        postcode: selectedAddress.postcode,
        delivery_instructions: deliveryInstructions || selectedAddress.delivery_instructions,
      };
    }
    return {
      address_line_1: line1,
      suburb,
      state,
      postcode,
      delivery_instructions: deliveryInstructions,
    };
  }, [fulfilment, selectedAddress, addressId, line1, suburb, state, postcode, deliveryInstructions]);

  const blockers = useMemo(() => {
    const list: { id: string; message: string; focusId?: string }[] = [];

    if (!name.trim()) {
      list.push({ id: "name", message: "Enter your full name.", focusId: "name" });
    }
    if (!email.trim()) {
      list.push({ id: "email", message: "Enter your email address.", focusId: "email" });
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      list.push({ id: "email", message: "Enter a valid email address.", focusId: "email" });
    }
    if (!phone.trim()) {
      list.push({ id: "phone", message: "Enter a contact phone number.", focusId: "phone" });
    }
    if (fulfilment === "third_party_delivery") {
      list.push({
        id: "fulfilment",
        message: "Third-party delivery is not available yet — choose pickup or restaurant delivery.",
      });
    }
    if (
      fulfilment === "restaurant_delivery" &&
      !(selectedAddress && addressId !== "new") &&
      !(line1.trim() && suburb.trim() && state.trim() && postcode.trim())
    ) {
      list.push({
        id: "address",
        message: "Complete the delivery address (street, suburb, state and postcode).",
        focusId: "line1",
      });
    }
    if (!termsAccepted) {
      list.push({ id: "terms", message: "Accept the terms and refund policy.", focusId: "terms" });
    }
    if (pricing && !pricing.minimum_order_met) {
      list.push({
        id: "minimum",
        message: `Minimum order of ${formatCents(pricing.minimum_order_cents)} is not met yet.`,
      });
    }

    return list;
  }, [
    name,
    email,
    phone,
    fulfilment,
    selectedAddress,
    addressId,
    line1,
    suburb,
    state,
    postcode,
    termsAccepted,
    pricing,
  ]);

  const canRequestQuote = Boolean(cart) && blockers.length === 0;

  const fieldError = (id: string) =>
    attempted ? blockers.find((b) => b.id === id)?.message : undefined;

  const requestQuote = async () => {
    if (blockers.length > 0) {
      setAttempted(true);
      const target = blockers.find((b) => b.focusId)?.focusId;
      if (target) {
        const el = document.getElementById(target);
        el?.scrollIntoView({ behavior: "smooth", block: "center" });
        el?.focus({ preventScroll: true });
      }
      return;
    }
    setQuoteLoading(true);
    setQuoteError(null);
    try {
      await cartApi.validateCart();
      const res = await cartApi.createQuote({
        fulfilment_type: fulfilment,
        address: addressPayload(),
        contact: { name, email, phone },
        order_notes: orderNotes || undefined,
        pickup_instructions: fulfilment === "pickup" ? pickupInstructions : undefined,
        contactless_delivery: fulfilment === "restaurant_delivery" ? contactless : undefined,
        terms_accepted: termsAccepted,
      });
      const data = res.data as { quote: QuoteResult };
      setQuote(data.quote);
      refetch();
    } catch (e: unknown) {
      const msg =
        e && typeof e === "object" && "message" in e && typeof (e as Error).message === "string"
          ? (e as Error).message
          : "Could not prepare checkout quote.";
      setQuoteError(msg);
      setQuote(null);
    } finally {
      setQuoteLoading(false);
    }
  };

  const displayPricing = quote?.pricing ?? pricing;

  if (isLoading) {
    return <main className="mx-auto max-w-6xl px-4 py-12">Loading checkout…</main>;
  }

  if (!cart) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-12 text-center">
        <h1 className="text-3xl">Your cart is empty</h1>
        <Link href="/restaurants" className="mt-6 inline-block text-[var(--color-burnt-orange)]">
          Browse restaurants
        </Link>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <Breadcrumbs
        items={[
          { label: "Home", href: "/" },
          { label: "Cart", href: "/cart" },
          { label: "Checkout" },
        ]}
      />
      <h1 className="mt-4 text-4xl">Checkout</h1>

      {pricing?.warnings?.length ? (
        <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="font-medium">Review your cart before checkout</p>
          <ul className="mt-2 list-disc pl-5">
            {pricing.warnings.map((w) => (
              <li key={`${w.code}-${w.cart_item_public_id ?? ""}`}>{w.message}</li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="mt-8 grid gap-8 lg:grid-cols-[1.2fr_0.8fr]">
        <div className="space-y-6">
          <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
            <h2 className="text-2xl">Customer information</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Full name" htmlFor="name" error={fieldError("name")}>
                <Input id="name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Alex Nguyen" />
              </Field>
              <Field label="Phone" htmlFor="phone" error={fieldError("phone")}>
                <Input id="phone" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+61 400 000 000" />
              </Field>
              <Field label="Email" htmlFor="email" className="sm:col-span-2" error={fieldError("email")}>
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="you@example.com"
                />
              </Field>
            </div>
          </section>

          <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
            <h2 className="text-2xl">Delivery or pickup</h2>
            <div className="mt-4 space-y-2">
              <Radio
                name="mode"
                label="Pickup"
                checked={fulfilment === "pickup"}
                onChange={() => setFulfilment("pickup")}
              />
              <Radio
                name="mode"
                label="Restaurant delivery"
                checked={fulfilment === "restaurant_delivery"}
                onChange={() => setFulfilment("restaurant_delivery")}
              />
              <Radio
                name="mode"
                label="Third-party delivery"
                checked={fulfilment === "third_party_delivery"}
                onChange={() => setFulfilment("third_party_delivery")}
                disabled
              />
              <p className="text-xs text-[var(--text-muted)]">Third-party delivery will be available in a later phase.</p>
            </div>

            {fulfilment === "pickup" ? (
              <Field label="Pickup instructions" htmlFor="pickup-notes" className="mt-4">
                <Textarea
                  id="pickup-notes"
                  value={pickupInstructions}
                  onChange={(e) => setPickupInstructions(e.target.value)}
                  placeholder="Car colour, name for collection"
                />
              </Field>
            ) : null}

            {fulfilment === "restaurant_delivery" ? (
              <div className="mt-4 grid gap-4">
                {isAuthenticated && addresses.length > 0 ? (
                  <Field label="Saved address" htmlFor="address">
                    <Select id="address" value={addressId} onChange={(e) => setAddressId(e.target.value)}>
                      {addresses.map((a) => (
                        <option key={a.public_id} value={a.public_id}>
                          {a.label ?? a.address_line_1} · {a.suburb}
                        </option>
                      ))}
                      <option value="new">Use a different address</option>
                    </Select>
                  </Field>
                ) : null}
                {(!isAuthenticated || addressId === "new" || addresses.length === 0) && (
                  <>
                    <Field label="Address line 1" htmlFor="line1" error={fieldError("address")}>
                      <Input id="line1" value={line1} onChange={(e) => setLine1(e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-3">
                      <Field label="Suburb" htmlFor="suburb">
                        <Input id="suburb" value={suburb} onChange={(e) => setSuburb(e.target.value)} />
                      </Field>
                      <Field label="State" htmlFor="state">
                        <Input id="state" value={state} onChange={(e) => setState(e.target.value)} />
                      </Field>
                      <Field label="Postcode" htmlFor="postcode">
                        <Input id="postcode" value={postcode} onChange={(e) => setPostcode(e.target.value)} />
                      </Field>
                    </div>
                  </>
                )}
                <Field label="Delivery instructions" htmlFor="notes">
                  <Textarea
                    id="notes"
                    value={deliveryInstructions}
                    onChange={(e) => setDeliveryInstructions(e.target.value)}
                    placeholder="Gate code, landmark, preferred entrance"
                  />
                </Field>
                <Checkbox
                  label="Contactless delivery"
                  checked={contactless}
                  onChange={(e) => setContactless(e.target.checked)}
                />
              </div>
            ) : null}
          </section>

          <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
            <h2 className="text-2xl">Payment</h2>
            <div className="mt-4">
              <PaymentMethodSelector value={paymentMethod} onChange={setPaymentMethod} />
            </div>
          </section>

          <section className="rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-white p-5 shadow-[var(--shadow-sm)]">
            <h2 className="text-2xl">Order notes</h2>
            <Field label="Notes for the restaurant" htmlFor="order-notes" className="mt-4">
              <Textarea id="order-notes" value={orderNotes} onChange={(e) => setOrderNotes(e.target.value)} />
            </Field>
            <Checkbox
              id="terms"
              className="mt-4"
              label="I agree to the terms, refund policy and restaurant partner conditions"
              checked={termsAccepted}
              onChange={(e) => setTermsAccepted(e.target.checked)}
            />
            {fieldError("terms") ? (
              <p className="mt-2 text-xs text-[var(--color-error)]" role="alert">
                {fieldError("terms")}
              </p>
            ) : null}
          </section>

          {isDev ? (
            <p className="text-sm text-[var(--text-muted)]">
              Dev: selected payment method is {paymentMethod}.
            </p>
          ) : null}
        </div>

        <aside className="h-fit rounded-[var(--radius-lg)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-5 shadow-[var(--shadow-sm)] lg:sticky lg:top-24">
          <h2 className="text-2xl">Order summary</h2>
          <p className="mt-1 text-sm text-[var(--text-muted)]">{cart.restaurant.trading_name}</p>
          <ul className="mt-4 space-y-3 text-sm">
            {cart.items.map((item) => (
              <li key={item.public_id} className="flex justify-between gap-3">
                <span>
                  {item.quantity}× {item.name}
                </span>
                <span>{formatCents(item.unit_price_snapshot_cents * item.quantity)}</span>
              </li>
            ))}
          </ul>
          {displayPricing ? (
            <div className="mt-4 space-y-2 border-t border-[var(--border-subtle)] pt-4 text-sm">
              <div className="flex justify-between">
                <span>Subtotal</span>
                <span>{formatCents(displayPricing.subtotal_cents)}</span>
              </div>
              {displayPricing.discount_cents > 0 ? (
                <div className="flex justify-between text-green-700">
                  <span>Discount</span>
                  <span>−{formatCents(displayPricing.discount_cents)}</span>
                </div>
              ) : null}
              <div className="flex justify-between">
                <span>Service fee (est.)</span>
                <span>{formatCents(displayPricing.service_fee_cents)}</span>
              </div>
              <div className="flex justify-between text-base font-semibold">
                <span>Total before delivery</span>
                <span>{formatCents(displayPricing.total_before_delivery_cents)}</span>
              </div>
              {!displayPricing.minimum_order_met ? (
                <p className="text-xs text-amber-700">
                  Minimum order {formatCents(displayPricing.minimum_order_cents)} not met yet.
                </p>
              ) : null}
            </div>
          ) : null}

          {quote ? (
            <p className="mt-4 text-xs text-green-700">
              Quote {quote.public_id.slice(0, 8)}… valid until {new Date(quote.expires_at).toLocaleString()}
            </p>
          ) : null}
          {quoteError ? <p className="mt-4 text-sm text-red-600">{quoteError}</p> : null}

          <Button
            className="mt-6 w-full"
            size="lg"
            loading={quoteLoading}
            onClick={() => void requestQuote()}
          >
            {quoteLoading ? "Preparing quote…" : quote ? "Refresh checkout quote" : "Prepare checkout quote"}
          </Button>

          {blockers.length > 0 ? (
            <div
              className="mt-3 rounded-[var(--radius-md)] border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900"
              role={attempted ? "alert" : undefined}
            >
              <p className="font-medium">Before you can continue to payment:</p>
              <ul className="mt-1.5 list-disc space-y-1 pl-4">
                {blockers.map((b) => (
                  <li key={b.id}>{b.message}</li>
                ))}
              </ul>
            </div>
          ) : null}
          {quote ? (
            <PlaceOrderButton
              className="mt-4"
              quotePublicId={quote.public_id}
              customerName={name}
              customerEmail={email}
              customerPhone={phone}
              pickupInstructions={pickupInstructions}
              deliveryInstructions={deliveryInstructions}
              customerNotes={orderNotes}
              contactlessDelivery={contactless}
              paymentMethod={paymentMethod}
              disabled={!canRequestQuote}
            />
          ) : (
            <p className="mt-3 text-center text-xs text-[var(--text-muted)]">
              Prepare a checkout quote, then place your order.
            </p>
          )}
        </aside>
      </div>
    </main>
  );
}
