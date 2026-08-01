"use client";

import { Button } from "@/components/ui/button";
import { Checkbox, Field, Input, Select } from "@/components/ui/forms";
import type { BusinessVerticalType } from "@/features/business/config/business-type-config";
import type {
  BakeryTypeDetails,
  ButcherTypeDetails,
  GroceryTypeDetails,
  MenuItemTypeDetails,
} from "@/features/restaurant/lib/menu-item-type-details";

type Props = {
  businessType: BusinessVerticalType;
  value: MenuItemTypeDetails;
  onChange: (next: MenuItemTypeDetails) => void;
};

export function MenuItemTypeDetailsFields({ businessType, value, onChange }: Props) {
  if (businessType === "bakery") {
    const details = value as BakeryTypeDetails;
    return (
      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Bakery details</h3>
        <Field label="Flavour" htmlFor="td-flavour">
          <Input
            id="td-flavour"
            value={details.flavour ?? ""}
            onChange={(e) => onChange({ ...details, schema: "bakery", flavour: e.target.value })}
          />
        </Field>
        <Checkbox
          label="Eggless option"
          checked={Boolean(details.eggless)}
          onChange={(e) => onChange({ ...details, schema: "bakery", eggless: e.target.checked })}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Minimum notice (hours)" htmlFor="td-notice">
            <Input
              id="td-notice"
              type="number"
              value={details.minimum_notice_hours ?? ""}
              onChange={(e) =>
                onChange({
                  ...details,
                  schema: "bakery",
                  minimum_notice_hours: e.target.value === "" ? null : Number(e.target.value),
                })
              }
            />
          </Field>
          <Field label="Serves (people)" htmlFor="td-serves">
            <Input
              id="td-serves"
              type="number"
              value={details.serves_people ?? ""}
              onChange={(e) =>
                onChange({
                  ...details,
                  schema: "bakery",
                  serves_people: e.target.value === "" ? null : Number(e.target.value),
                })
              }
            />
          </Field>
        </div>
        <Checkbox
          label="Allow custom message"
          checked={Boolean(details.custom_message_allowed)}
          onChange={(e) =>
            onChange({ ...details, schema: "bakery", custom_message_allowed: e.target.checked })
          }
        />
      </section>
    );
  }

  if (businessType === "grocery") {
    const details = value as GroceryTypeDetails;
    return (
      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Grocery details</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Brand" htmlFor="td-brand">
            <Input
              id="td-brand"
              value={details.brand ?? ""}
              onChange={(e) => onChange({ ...details, schema: "grocery", brand: e.target.value })}
            />
          </Field>
          <Field label="Barcode" htmlFor="td-barcode">
            <Input
              id="td-barcode"
              value={details.barcode ?? ""}
              onChange={(e) => onChange({ ...details, schema: "grocery", barcode: e.target.value })}
            />
          </Field>
          <Field label="Manufacturer" htmlFor="td-manufacturer">
            <Input
              id="td-manufacturer"
              value={details.manufacturer ?? ""}
              onChange={(e) =>
                onChange({ ...details, schema: "grocery", manufacturer: e.target.value })
              }
            />
          </Field>
          <Field label="Package size" htmlFor="td-package">
            <Input
              id="td-package"
              value={details.package_size ?? ""}
              onChange={(e) =>
                onChange({ ...details, schema: "grocery", package_size: e.target.value })
              }
              placeholder="e.g. 500g"
            />
          </Field>
          <Field label="Max purchase quantity" htmlFor="td-max-qty">
            <Input
              id="td-max-qty"
              type="number"
              value={details.max_purchase_quantity ?? ""}
              onChange={(e) =>
                onChange({
                  ...details,
                  schema: "grocery",
                  max_purchase_quantity: e.target.value === "" ? null : Number(e.target.value),
                })
              }
            />
          </Field>
        </div>
      </section>
    );
  }

  if (businessType === "butcher") {
    const details = value as ButcherTypeDetails;
    const variants = details.fixed_weight_variants ?? [];
    return (
      <section className="rounded-lg border p-5 bg-white space-y-4">
        <h3 className="text-lg font-semibold">Butchery details</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Animal type" htmlFor="td-animal">
            <Input
              id="td-animal"
              value={details.animal_type ?? ""}
              onChange={(e) =>
                onChange({ ...details, schema: "butcher", animal_type: e.target.value })
              }
              placeholder="e.g. Chicken"
            />
          </Field>
          <Field label="Cut type" htmlFor="td-cut">
            <Input
              id="td-cut"
              value={details.cut_type ?? ""}
              onChange={(e) =>
                onChange({ ...details, schema: "butcher", cut_type: e.target.value })
              }
              placeholder="e.g. Thigh"
            />
          </Field>
          <Field label="Fresh / frozen" htmlFor="td-storage">
            <Select
              id="td-storage"
              value={details.storage ?? ""}
              onChange={(e) =>
                onChange({
                  ...details,
                  schema: "butcher",
                  storage: (e.target.value || null) as "fresh" | "frozen" | null,
                })
              }
            >
              <option value="">Select…</option>
              <option value="fresh">Fresh</option>
              <option value="frozen">Frozen</option>
            </Select>
          </Field>
          <Field label="Default fixed weight (grams)" htmlFor="td-weight">
            <Input
              id="td-weight"
              type="number"
              value={details.fixed_weight_grams ?? ""}
              onChange={(e) =>
                onChange({
                  ...details,
                  schema: "butcher",
                  fixed_weight_grams: e.target.value === "" ? null : Number(e.target.value),
                })
              }
            />
          </Field>
        </div>
        <div className="flex flex-wrap gap-4">
          <Checkbox
            label="Bone-in"
            checked={details.bone_in === true}
            onChange={(e) =>
              onChange({ ...details, schema: "butcher", bone_in: e.target.checked })
            }
          />
          <Checkbox
            label="Skin-on"
            checked={details.skin_on === true}
            onChange={(e) =>
              onChange({ ...details, schema: "butcher", skin_on: e.target.checked })
            }
          />
        </div>
        <div className="space-y-3">
          <p className="text-sm font-medium">Fixed-weight variants</p>
          {variants.map((row, idx) => (
            <div key={idx} className="grid gap-3 sm:grid-cols-[1fr_120px_auto]">
              <Input
                placeholder="Variant name"
                value={row.name}
                onChange={(e) => {
                  const next = variants.map((v, i) =>
                    i === idx ? { ...v, name: e.target.value } : v,
                  );
                  onChange({ ...details, schema: "butcher", fixed_weight_variants: next });
                }}
              />
              <Input
                type="number"
                placeholder="Grams"
                value={row.weight_grams || ""}
                onChange={(e) => {
                  const next = variants.map((v, i) =>
                    i === idx ? { ...v, weight_grams: Number(e.target.value) || 0 } : v,
                  );
                  onChange({ ...details, schema: "butcher", fixed_weight_variants: next });
                }}
              />
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() =>
                  onChange({
                    ...details,
                    schema: "butcher",
                    fixed_weight_variants: variants.filter((_, i) => i !== idx),
                  })
                }
              >
                Remove
              </Button>
            </div>
          ))}
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() =>
              onChange({
                ...details,
                schema: "butcher",
                fixed_weight_variants: [...variants, { name: "", weight_grams: 0 }],
              })
            }
          >
            Add fixed-weight variant
          </Button>
        </div>
      </section>
    );
  }

  return null;
}
