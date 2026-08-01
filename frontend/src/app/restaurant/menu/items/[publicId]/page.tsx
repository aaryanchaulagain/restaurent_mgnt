"use client";

import { useParams, useRouter } from "next/navigation";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { AdminShell } from "@/components/layout/admin-shell";
import { Button } from "@/components/ui/button";
import { MenuItemEditor } from "@/features/restaurant/components/menu-item-editor";
import { restaurantMenuAdminApi } from "@/features/restaurant/api/restaurant-admin-api";
import { useRestaurantShell } from "@/features/restaurant/hooks/use-restaurant-shell";

export default function RestaurantMenuItemPage() {
  const params = useParams<{ publicId: string }>();
  const { brand, portalLabel, items, copy } = useRestaurantShell();
  const router = useRouter();
  const qc = useQueryClient();

  const dupMutation = useMutation({
    mutationFn: () => restaurantMenuAdminApi.duplicateItem(params.publicId),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ["restaurant", "menu-items"] });
      const newId = (res.data as { item: { public_id: string } }).item.public_id;
      router.push(`/restaurant/menu/items/${newId}`);
    },
  });

  return (
    <AdminShell
      brand={brand}
      portalLabel={portalLabel}
      items={items}
      title={`Edit ${copy.productLabel.toLowerCase()}`}
      subtitle={
        copy.supportsModifiers
          ? `Update ${copy.productLabel.toLowerCase()} details, variants and modifiers`
          : `Update ${copy.productLabel.toLowerCase()} details and variants`
      }
      actions={
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => dupMutation.mutate()} loading={dupMutation.isPending}>
            Duplicate
          </Button>
        </div>
      }
    >
      <MenuItemEditor publicId={params.publicId} />
    </AdminShell>
  );
}
