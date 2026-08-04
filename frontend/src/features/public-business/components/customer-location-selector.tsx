"use client";

import { useCallback, useEffect, useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Field, Input, Radio, Select } from "@/components/ui/forms";
import { useAuth } from "@/features/auth/hooks/use-auth";
import { apiGet, ApiError } from "@/lib/api/client";
import {
  publicBusinessApi,
  type BranchRecommendationFulfilment,
  type BranchRecommendationResponse,
} from "@/features/public-business/api/public-business-api";
import { requestBrowserLocation } from "@/features/public-business/lib/browser-location";
import { BranchRecommendationList } from "./branch-recommendation-list";

type SavedAddress = {
  public_id: string;
  label?: string | null;
  suburb: string;
  state: string;
  postcode: string;
  is_default: boolean;
};

type Props = {
  businessSlug: string;
};

/**
 * Location-aware branch discovery. Never mutates the cart.
 * Browser coordinates stay in component memory only.
 */
export function CustomerLocationSelector({ businessSlug }: Props) {
  const { isAuthenticated } = useAuth();
  const [fulfilment, setFulfilment] = useState<BranchRecommendationFulfilment>("delivery");
  const [postcode, setPostcode] = useState("");
  const [city, setCity] = useState("");
  const [state, setState] = useState("");
  const [addressId, setAddressId] = useState<string>("");
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [geoConsentOpen, setGeoConsentOpen] = useState(false);
  const [geoMessage, setGeoMessage] = useState<string | null>(null);
  // In-memory only — never written to localStorage.
  const [coords, setCoords] = useState<{ latitude: number; longitude: number } | null>(null);
  const [result, setResult] = useState<BranchRecommendationResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated) {
      setAddresses([]);
      setAddressId("");
      return;
    }
    void apiGet<{ addresses: SavedAddress[] }>("/api/v1/customer/addresses")
      .then((res) => {
        setAddresses(res.data.addresses);
        const def = res.data.addresses.find((a) => a.is_default) ?? res.data.addresses[0];
        if (def) setAddressId(def.public_id);
      })
      .catch(() => undefined);
  }, [isAuthenticated]);

  const mutation = useMutation({
    mutationFn: async () => {
      const body: Parameters<typeof publicBusinessApi.recommendBranches>[1] = {
        fulfilment,
      };
      if (isAuthenticated && addressId) {
        body.address_public_id = addressId;
      } else {
        if (postcode.trim()) body.postcode = postcode.trim();
        if (city.trim()) body.city = city.trim();
        if (state.trim()) body.state = state.trim();
        if (coords) {
          body.latitude = coords.latitude;
          body.longitude = coords.longitude;
        }
      }
      const res = await publicBusinessApi.recommendBranches(
        businessSlug,
        body,
        Boolean(isAuthenticated && addressId),
      );
      return res.data;
    },
    onSuccess: (data) => {
      setResult(data);
      setError(null);
    },
    onError: (e) => {
      setResult(null);
      if (e instanceof ApiError) {
        setError(e.message);
        return;
      }
      setError("Could not find nearby branches.");
    },
  });

  const runSearch = useCallback(() => {
    mutation.mutate();
  }, [mutation]);

  const onUseLocation = async () => {
    setGeoMessage(null);
    const loc = await requestBrowserLocation();
    if (!loc.ok) {
      if (loc.reason === "denied") {
        setGeoMessage("Location permission denied. Enter a postcode instead, or continue browsing manually.");
      } else if (loc.reason === "unsupported") {
        setGeoMessage("This browser does not support location. Enter a postcode instead.");
      } else {
        setGeoMessage("Location unavailable. Enter a postcode instead.");
      }
      setGeoConsentOpen(false);
      return;
    }
    setCoords({ latitude: loc.latitude, longitude: loc.longitude });
    setGeoConsentOpen(false);
    setGeoMessage("Using your current location for this search only. It will not be saved.");
  };

  return (
    <div className="space-y-5 rounded-[var(--radius-xl)] border border-[var(--border-subtle)] bg-[var(--surface-elevated)] p-5 shadow-[var(--shadow-sm)]">
      <div>
        <h2 className="text-lg font-semibold text-[var(--text-primary)]">Find a location near you</h2>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">
          Recommendations help you choose a branch. Your cart never changes until you add an item.
        </p>
      </div>

      <div className="flex flex-wrap gap-4">
        <Radio
          name="fulfilment"
          label="Delivery"
          checked={fulfilment === "delivery"}
          onChange={() => setFulfilment("delivery")}
        />
        <Radio
          name="fulfilment"
          label="Pickup"
          checked={fulfilment === "pickup"}
          onChange={() => setFulfilment("pickup")}
        />
      </div>

      {isAuthenticated && addresses.length > 0 ? (
        <Field label="Saved address" htmlFor="saved-address">
          <Select
            id="saved-address"
            value={addressId}
            onChange={(e) => {
              setAddressId(e.target.value);
              setCoords(null);
            }}
          >
            {addresses.map((a) => (
              <option key={a.public_id} value={a.public_id}>
                {(a.label ?? a.suburb) + ` · ${a.postcode}`}
              </option>
            ))}
            <option value="">Use postcode instead</option>
          </Select>
        </Field>
      ) : null}

      {(!isAuthenticated || !addressId) && fulfilment === "delivery" ? (
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Postcode" htmlFor="postcode">
            <Input
              id="postcode"
              value={postcode}
              onChange={(e) => setPostcode(e.target.value)}
              placeholder="4000"
            />
          </Field>
          <Field label="Suburb / city" htmlFor="city">
            <Input id="city" value={city} onChange={(e) => setCity(e.target.value)} />
          </Field>
          <Field label="State" htmlFor="state">
            <Input id="state" value={state} onChange={(e) => setState(e.target.value)} />
          </Field>
        </div>
      ) : null}

      <div className="flex flex-wrap gap-3">
        <Button type="button" onClick={() => runSearch()} loading={mutation.isPending}>
          Find branches
        </Button>
        <Button type="button" variant="outline" onClick={() => setGeoConsentOpen((v) => !v)}>
          Use my current location
        </Button>
        {coords ? (
          <Button type="button" variant="ghost" onClick={() => setCoords(null)}>
            Clear temporary location
          </Button>
        ) : null}
      </div>

      {geoConsentOpen ? (
        <div className="rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-4 text-sm">
          <p className="text-[var(--text-secondary)]">
            Use your current location to find nearby branches. Your location will be used for this search
            and will not be saved unless you choose to save it.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button type="button" size="sm" onClick={() => void onUseLocation()}>
              Allow location
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setGeoConsentOpen(false)}>
              Enter address instead
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => {
                setGeoConsentOpen(false);
                setGeoMessage(null);
              }}
            >
              Continue without location
            </Button>
          </div>
        </div>
      ) : null}

      {geoMessage ? <p className="text-sm text-[var(--text-secondary)]">{geoMessage}</p> : null}
      {error ? (
        <p className="text-sm text-[var(--color-error)]" role="alert">
          {error}
        </p>
      ) : null}

      {result ? (
        <div className="space-y-3">
          <p className="text-sm text-[var(--text-secondary)]">
            {result.recommended_branch_public_id
              ? "One branch is marked Recommended — choose it explicitly to open its catalogue."
              : "No eligible branch for this fulfilment. You can still browse locations manually below."}
          </p>
          <BranchRecommendationList businessSlug={businessSlug} branches={result.branches} />
        </div>
      ) : null}
    </div>
  );
}
