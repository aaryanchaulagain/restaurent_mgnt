"use client";

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { cartApi, type CartPricing, type CartState } from "../api/cart-api";
import { ApiError } from "@/lib/api/client";

type AddItemPayload = Parameters<typeof cartApi.addItem>[0];

type ConflictState = {
  open: boolean;
  data: {
    current_restaurant?: { slug: string; trading_name: string } | null;
    requested_restaurant?: { slug: string; trading_name: string } | null;
  } | null;
  pending: AddItemPayload | null;
};

type CartContextValue = {
  cart: CartState | null;
  pricing: CartPricing | null;
  itemCount: number;
  isLoading: boolean;
  refetch: () => void;
  addItem: (payload: AddItemPayload) => Promise<void>;
  conflict: ConflictState;
  clearConflict: () => void;
  confirmReplaceRestaurant: () => Promise<void>;
  updateQuantity: (lineId: string, quantity: number) => Promise<void>;
  removeLine: (lineId: string) => Promise<void>;
};

const CartContext = createContext<CartContextValue | null>(null);

const emptyConflict: ConflictState = { open: false, data: null, pending: null };

export function CartProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const [conflict, setConflict] = useState<ConflictState>(emptyConflict);

  const query = useQuery({
    queryKey: ["cart"],
    queryFn: async () => {
      const res = await cartApi.getCart();
      return res.data;
    },
  });

  const addMutation = useMutation({
    mutationFn: cartApi.addItem,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });

  const addItem = useCallback(
    async (payload: AddItemPayload) => {
      try {
        await addMutation.mutateAsync(payload);
      } catch (e) {
        if (e instanceof ApiError && e.status === 409) {
          const meta = (e.envelope?.data ?? null) as ConflictState["data"];
          setConflict({ open: true, data: meta, pending: payload });
          return;
        }
        throw e;
      }
    },
    [addMutation],
  );

  const clearConflict = useCallback(() => setConflict(emptyConflict), []);

  const confirmReplaceRestaurant = useCallback(async () => {
    if (!conflict.pending) return;
    try {
      await addMutation.mutateAsync({ ...conflict.pending, replace_restaurant: true });
      setConflict(emptyConflict);
    } catch (e) {
      setConflict(emptyConflict);
      throw e;
    }
  }, [addMutation, conflict.pending]);

  const updateMutation = useMutation({
    mutationFn: ({ id, quantity }: { id: string; quantity: number }) => cartApi.updateItem(id, { quantity }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });

  const removeMutation = useMutation({
    mutationFn: (id: string) => cartApi.removeItem(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });

  const value: CartContextValue = useMemo(
    () => ({
      cart: query.data?.cart ?? null,
      pricing: query.data?.pricing ?? null,
      itemCount: query.data?.cart?.items.reduce((s, i) => s + i.quantity, 0) ?? 0,
      isLoading: query.isLoading,
      refetch: () => void query.refetch(),
      addItem,
      conflict,
      clearConflict,
      confirmReplaceRestaurant,
      updateQuantity: async (id, quantity) => {
        await updateMutation.mutateAsync({ id, quantity });
      },
      removeLine: async (id) => {
        await removeMutation.mutateAsync(id);
      },
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- query.data and query.isLoading are the reactive values we need; query object identity changes on every render
    [
      query.data,
      query.isLoading,
      addItem,
      conflict,
      clearConflict,
      confirmReplaceRestaurant,
      updateMutation,
      removeMutation,
    ],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within CartProvider");
  return ctx;
}
