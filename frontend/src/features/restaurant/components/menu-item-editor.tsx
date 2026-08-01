"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/feedback";
import { Checkbox, Field, FileUpload, Input, Select, Textarea } from "@/components/ui/forms";
import { useToast } from "@/components/ui/navigation";
import { restaurantMenuAdminApi, type AdminMenuItem } from "@/features/restaurant/api/restaurant-admin-api";
import { formatCents } from "@/lib/utils";
import { ApiError } from "@/lib/api/client";

type Variant = { name: string; price_cents: number | string; is_default: boolean; sku: string };
type Props = { publicId?: string; restaurantKey?: string };

export function MenuItemEditor({ publicId, restaurantKey = "default" }: Props) {
  const router = useRouter();
  const qc = useQueryClient();
  const toast = useToast();
  const isEdit = Boolean(publicId);

  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [shortDesc, setShortDesc] = useState("");
  const [description, setDescription] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [basePriceCents, setBasePriceCents] = useState("");
  const [compareAtPriceCents, setCompareAtPriceCents] = useState("");
  const [costPriceCents, setCostPriceCents] = useState("");
  const [prepMins, setPrepMins] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [isAvailable, setIsAvailable] = useState(true);
  const [isFeatured, setIsFeatured] = useState(false);
  const [isVegetarian, setIsVegetarian] = useState(false);
  const [isVegan, setIsVegan] = useState(false);
  const [isGlutenFree, setIsGlutenFree] = useState(false);
  const [isHalal, setIsHalal] = useState(false);
  const [variants, setVariants] = useState<Variant[]>([]);
  const [modifierGroupIds, setModifierGroupIds] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);

  const categories = useQuery({
    queryKey: ["restaurant", restaurantKey, "categories"],
    queryFn: async () => (await restaurantMenuAdminApi.listCategories()).data.categories,
  });

  const ensureCategory = useMutation({
    mutationFn: async () => {
      const res = await restaurantMenuAdminApi.createCategory({
        name: "General",
        is_active: true,
      });
      return res.data.category;
    },
    onSuccess: (cat) => {
      qc.invalidateQueries({ queryKey: ["restaurant", restaurantKey, "categories"] });
      setCategoryId(cat.public_id);
    },
  });

  const bootstrappedCategory = useRef(false);
  useEffect(() => {
    bootstrappedCategory.current = false;
    setCategoryId("");
  }, [restaurantKey]);

  useEffect(() => {
    if (!categories.isSuccess || isEdit || bootstrappedCategory.current) return;
    const list = categories.data ?? [];
    if (list.length === 0) {
      bootstrappedCategory.current = true;
      ensureCategory.mutate();
      return;
    }
    if (!categoryId) {
      setCategoryId(list[0].public_id);
    }
  }, [categories.isSuccess, categories.data, categoryId, isEdit, ensureCategory]);

  const modifierGroups = useQuery({
    queryKey: ["restaurant", restaurantKey, "modifier-groups"],
    queryFn: async () => (await restaurantMenuAdminApi.listModifierGroups()).data.modifier_groups as Array<{ public_id: string; name: string; is_required: boolean }>,
  });

  const existingItem = useQuery({
    queryKey: ["restaurant", "menu-item", publicId],
    queryFn: async () => (await restaurantMenuAdminApi.getItem(publicId!)).data.item,
    enabled: isEdit,
  });

  const derivedItem = useMemo(() => {
    if (!existingItem.data) return null;
    const item = existingItem.data;
    return {
      name: item.name,
      slug: item.slug,
      shortDesc: item.short_description ?? "",
      description: item.description ?? "",
      basePriceCents: String(item.base_price_cents),
      compareAtPriceCents: item.compare_at_price_cents != null ? String(item.compare_at_price_cents) : "",
      costPriceCents: item.cost_price_cents != null ? String(item.cost_price_cents) : "",
      isActive: item.is_active,
      isAvailable: item.is_available,
      isFeatured: item.is_featured ?? false,
    };
  }, [existingItem.data]);

  const prevDerivedRef = useRef(derivedItem);
  useEffect(() => {
    if (derivedItem && derivedItem !== prevDerivedRef.current) {
      prevDerivedRef.current = derivedItem;
      setName(derivedItem.name);
      setSlug(derivedItem.slug);
      setShortDesc(derivedItem.shortDesc);
      setDescription(derivedItem.description);
      setBasePriceCents(derivedItem.basePriceCents);
      setCompareAtPriceCents(derivedItem.compareAtPriceCents);
      setCostPriceCents(derivedItem.costPriceCents);
      setIsActive(derivedItem.isActive);
      setIsAvailable(derivedItem.isAvailable);
      setIsFeatured(derivedItem.isFeatured);
    }
  }, [derivedItem]);

  useEffect(() => {
    const url = existingItem.data?.image?.card_url ?? existingItem.data?.image?.original_url;
    if (url && !imageFile) setImagePreview(url);
  }, [existingItem.data, imageFile]);

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!categoryId) {
        throw new ApiError("Choose a category before creating the item.", 422, {
          menu_category_public_id: ["A category is required."],
        });
      }
      if (!name.trim()) {
        throw new ApiError("Item name is required.", 422, {
          name: ["Item name is required."],
        });
      }
      if (!basePriceCents || Number.isNaN(Number(basePriceCents))) {
        throw new ApiError("Base price is required.", 422, {
          base_price_cents: ["Enter price in cents (e.g. 5000 for $50.00)."],
        });
      }

      const body: Record<string, unknown> = {
        name,
        slug: slug || name.toLowerCase().replace(/[^a-z0-9]+/g, "-"),
        short_description: shortDesc || null,
        description: description || null,
        menu_category_public_id: categoryId,
        base_price_cents: Number(basePriceCents),
        compare_at_price_cents: compareAtPriceCents ? Number(compareAtPriceCents) : null,
        cost_price_cents: costPriceCents ? Number(costPriceCents) : null,
        preparation_minutes: prepMins ? Number(prepMins) : null,
        is_active: isActive,
        is_available: isAvailable,
        is_featured: isFeatured,
        is_vegetarian: isVegetarian,
        is_vegan: isVegan,
        is_gluten_free: isGlutenFree,
        is_halal: isHalal,
      };

      let itemId = publicId;
      if (isEdit && publicId) {
        await restaurantMenuAdminApi.updateItem(publicId, body);
      } else {
        const res = await restaurantMenuAdminApi.createItem(body);
        itemId = (res.data as { item: AdminMenuItem }).item.public_id;
      }

      if (imageFile && itemId) {
        await restaurantMenuAdminApi.uploadItemImage(itemId, imageFile);
      }

      if (variants.length > 0 && itemId) {
        await restaurantMenuAdminApi.syncVariants(
          itemId,
          variants.map((v) => ({
            name: v.name,
            price_cents: Number(v.price_cents),
            is_default: v.is_default,
            sku: v.sku || null,
          })),
        );
      }

      if (modifierGroupIds.length > 0 && itemId) {
        await restaurantMenuAdminApi.syncModifierGroups(itemId, modifierGroupIds);
      }

      return itemId;
    },
    onSuccess: (itemId) => {
      setSaved(true);
      setErrors({});
      qc.invalidateQueries({ queryKey: ["restaurant", "menu-items"] });
      qc.invalidateQueries({ queryKey: ["admin", "restaurant"] });
      qc.invalidateQueries({ queryKey: ["platform-restaurant"] });
      qc.invalidateQueries({ queryKey: ["public-menu"] });
      toast.push({
        title: isEdit ? "Item updated" : "Item created",
        description: isEdit ? "Changes are live." : "Your dish was saved.",
        tone: "success",
      });
      setTimeout(() => setSaved(false), 5000);
      if (!isEdit && itemId) router.push(`/restaurant/menu/items/${itemId}`);
    },
    onError: (e) => {
      const nextErrors =
        e instanceof ApiError && e.errors
          ? e.errors
          : { general: [e instanceof Error ? e.message : "Save failed"] };
      setErrors(nextErrors);
      const first =
        Object.values(nextErrors).flat()[0] ??
        (e instanceof Error ? e.message : "Save failed");
      toast.push({
        title: "Could not save item",
        description: first,
        tone: "error",
      });
    },
  });

  const addVariant = () => setVariants((v) => [...v, { name: "", price_cents: "", is_default: v.length === 0, sku: "" }]);
  const removeVariant = (idx: number) => setVariants((v) => v.filter((_, i) => i !== idx));
  const setVariantDefault = (idx: number) => setVariants((v) => v.map((vr, i) => ({ ...vr, is_default: i === idx })));

  const toggleModifierGroup = (id: string) => {
    setModifierGroupIds((ids) => ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]);
  };

  const fieldError = (key: string) => errors[key]?.[0];
  const submitError =
    errors.general?.[0] ??
    Object.values(errors).flat()[0] ??
    (categories.isError
      ? categories.error instanceof Error
        ? categories.error.message
        : "Could not load categories for this restaurant."
      : null) ??
    (ensureCategory.isError
      ? ensureCategory.error instanceof Error
        ? ensureCategory.error.message
        : "Could not create a category."
      : null);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      {saved ? <p className="text-sm text-green-700">Saved successfully.</p> : null}
      {errors.general ? <p className="text-sm text-red-600">{errors.general[0]}</p> : null}

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Basic information</h3>
        <Field label="Item name" htmlFor="item-name" error={fieldError("name")}>
          <Input id="item-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Himalayan Dal Bhat Thali" />
        </Field>
        <Field label="URL slug" htmlFor="item-slug" error={fieldError("slug")}>
          <Input id="item-slug" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="dal-bhat-thali" />
        </Field>
        <Field
          label="Category"
          htmlFor="item-cat"
          error={fieldError("menu_category_public_id")}
          hint={
            ensureCategory.isPending
              ? "Creating a default category…"
              : (categories.data?.length ?? 0) === 0
                ? "A category is required — we’ll create “General” automatically."
                : undefined
          }
        >
          <Select
            id="item-cat"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
            required
          >
            <option value="">Select category</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.public_id} value={c.public_id}>
                {c.name}
              </option>
            ))}
          </Select>
        </Field>
        {(categories.data?.length ?? 0) === 0 && !ensureCategory.isPending ? (
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => ensureCategory.mutate()}
            disabled={ensureCategory.isPending}
          >
            Create “General” category
          </Button>
        ) : null}
        <Field label="Short description" htmlFor="item-short"><Textarea id="item-short" value={shortDesc} onChange={(e) => setShortDesc(e.target.value)} /></Field>
        <Field label="Full description" htmlFor="item-desc"><Textarea id="item-desc" value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Pricing</h3>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            label="Base price (cents)"
            htmlFor="base-price"
            error={fieldError("base_price_cents")}
            hint="Example: 1850 = $18.50"
          >
            <Input
              id="base-price"
              type="number"
              value={basePriceCents}
              onChange={(e) => setBasePriceCents(e.target.value)}
            />
          </Field>
          <Field label="Compare-at price (cents)" htmlFor="compare-price">
            <Input id="compare-price" type="number" value={compareAtPriceCents} onChange={(e) => setCompareAtPriceCents(e.target.value)} />
          </Field>
          <Field label="Cost price (cents, private)" htmlFor="cost-price" hint="Never shown publicly">
            <Input id="cost-price" type="number" value={costPriceCents} onChange={(e) => setCostPriceCents(e.target.value)} />
          </Field>
        </div>
        <Field label="Preparation time (minutes)" htmlFor="prep-time">
          <Input id="prep-time" type="number" value={prepMins} onChange={(e) => setPrepMins(e.target.value)} />
        </Field>
        {basePriceCents ? <p className="text-xs text-[var(--text-muted)]">Display price: {formatCents(Number(basePriceCents))}</p> : null}
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Image</h3>
        {imagePreview ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={imagePreview} alt="" className="h-40 w-full rounded-md object-cover" />
        ) : null}
        <FileUpload
          label="Upload item image"
          accept="image/png,image/jpeg,image/webp"
          fileName={imageFile?.name ?? null}
          onChange={(file) => {
            setImageFile(file);
            if (file) {
              const url = URL.createObjectURL(file);
              setImagePreview(url);
            } else {
              setImagePreview(existingItem.data?.image?.card_url ?? null);
            }
          }}
        />
        {fieldError("file") ? <p className="text-xs text-red-600">{fieldError("file")}</p> : null}
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Variants</h3>
        {variants.map((v, idx) => (
          <div key={idx} className="grid gap-3 sm:grid-cols-[1fr_100px_80px_auto] border-b pb-3">
            <Input placeholder="Variant name" value={v.name} onChange={(e) => setVariants((vs) => vs.map((vr, i) => i === idx ? { ...vr, name: e.target.value } : vr))} />
            <Input type="number" placeholder="Cents" value={v.price_cents} onChange={(e) => setVariants((vs) => vs.map((vr, i) => i === idx ? { ...vr, price_cents: e.target.value } : vr))} />
            <label className="flex items-center gap-1 text-xs"><input type="radio" name="default-variant" checked={v.is_default} onChange={() => setVariantDefault(idx)} /> Default</label>
            <Button size="sm" variant="ghost" onClick={() => removeVariant(idx)}>Remove</Button>
          </div>
        ))}
        <Button type="button" variant="outline" size="sm" onClick={addVariant}>Add variant</Button>
        {fieldError("variants") ? <p className="text-xs text-red-600">{fieldError("variants")}</p> : null}
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Modifier groups</h3>
        {(modifierGroups.data ?? []).map((g) => (
          <Checkbox key={g.public_id} label={`${g.name}${g.is_required ? " (required)" : ""}`} checked={modifierGroupIds.includes(g.public_id)} onChange={() => toggleModifierGroup(g.public_id)} />
        ))}
        {modifierGroups.data?.length === 0 ? <p className="text-sm text-[var(--text-muted)]">No modifier groups yet. <a href="/restaurant/menu/modifiers" className="underline">Create one</a></p> : null}
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Dietary &amp; allergens</h3>
        <div className="flex flex-wrap gap-4">
          <Checkbox label="Vegetarian" checked={isVegetarian} onChange={(e) => setIsVegetarian(e.target.checked)} />
          <Checkbox label="Vegan" checked={isVegan} onChange={(e) => setIsVegan(e.target.checked)} />
          <Checkbox label="Gluten free" checked={isGlutenFree} onChange={(e) => setIsGlutenFree(e.target.checked)} />
          <Checkbox label="Halal" checked={isHalal} onChange={(e) => setIsHalal(e.target.checked)} />
        </div>
      </section>

      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Availability</h3>
        <div className="flex flex-wrap gap-4">
          <Checkbox label="Active" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          <Checkbox label="Available" checked={isAvailable} onChange={(e) => setIsAvailable(e.target.checked)} />
          <Checkbox label="Featured" checked={isFeatured} onChange={(e) => setIsFeatured(e.target.checked)} />
        </div>
      </section>

      <section className="rounded-lg border p-5 bg-white">
        <h3 className="text-lg font-semibold mb-3">Public preview</h3>
        <div className="rounded border p-4 bg-[var(--surface-muted)]">
          <p className="font-semibold">{name || "Item name"}</p>
          <p className="text-sm text-[var(--text-muted)]">{shortDesc || "Short description"}</p>
          <p className="mt-1 font-medium text-[var(--color-burnt-orange)]">{basePriceCents ? formatCents(Number(basePriceCents)) : "$0.00"}</p>
          <div className="mt-2 flex gap-1">
            {isVegetarian ? <Badge tone="success">Vegetarian</Badge> : null}
            {isVegan ? <Badge tone="success">Vegan</Badge> : null}
            {isGlutenFree ? <Badge tone="info">GF</Badge> : null}
            {isHalal ? <Badge tone="info">Halal</Badge> : null}
            {!isAvailable ? <Badge tone="error">Sold out</Badge> : null}
          </div>
        </div>
      </section>

      <div className="sticky bottom-0 z-10 -mx-1 space-y-3 border-t border-[var(--border-subtle)] bg-[var(--surface-porcelain)]/95 px-1 py-4 backdrop-blur">
        {submitError ? (
          <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {submitError}
          </p>
        ) : null}
        <div className="flex gap-3">
          <Button
            type="button"
            onClick={() => saveMutation.mutate()}
            loading={saveMutation.isPending}
            disabled={!categoryId || ensureCategory.isPending || categories.isLoading}
          >
            {isEdit ? "Save changes" : "Create item"}
          </Button>
          <Button type="button" variant="outline" onClick={() => router.push("/restaurant/menu")}>
            Cancel
          </Button>
        </div>
        {!categoryId ? (
          <p className="text-xs text-[var(--text-muted)]">
            Waiting for a category… If this hangs, open{" "}
            <a href="/admin/menus" className="underline">
              Menus
            </a>{" "}
            and add the item from Suvakamana again.
          </p>
        ) : null}
      </div>
    </div>
  );
}
