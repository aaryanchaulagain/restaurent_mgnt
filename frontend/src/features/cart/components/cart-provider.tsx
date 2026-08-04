"use client";

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { cartApi, type CartPricing, type CartState } from "../api/cart-api";
import { ApiError } from "@/lib/api/client";

type AddItemPayload = Parameters<typeof cartApi.addItem>[0];

type ConflictData = {
  current_cart?: {
    business_name?: string | null;
    branch_name?: string | null;
    restaurant_slug?: string | null;
  } | null;
  requested_branch?: {
    business_name?: string | null;
    branch_name?: string | null;
    restaurant_slug?: string | null;
  } | null;
  current_restaurant?: { slug: string; trading_name: string } | null;
  requested_restaurant?: { slug: string; trading_name: string } | null;
};

type ConflictState = {
  open: boolean;
  data: ConflictData | null;
  pending: AddItemPayload | null;
};

export type AddItemResult = { ok: true } | { ok: false; conflict: true };

type CartContextValue = {
  cart: CartState | null;
  pricing: CartPricing | null;
  itemCount: number;
  isLoading: boolean;
  refetch: () => void;
  addItem: (payload: AddItemPayload) => Promise<AddItemResult>;
  conflict: ConflictState;
  clearConflict: () => void;
  confirmReplaceRestaurant: () => Promise<void>;
  updateQuantity: (lineId: string, quantity: number) => Promise<void>;
  removeLine: (lineId: string) => Promise<void>;
  clearCart: () => Promise<void>;
};

const CartContext = createContext<CartContextValue | null>(null);

const emptyConflict: ConflictState = { open: false, data: null, pending: null };

export function CartProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const [conflict, setConflict] = useState<ConflictState>(emptyConflict);

  const query = useQuery({
    queryKey: ["cart"],
    queryFn: async () => {
      try {
        const res = await cartApi.getCart();
        return res.data;
      } catch (e) {
        if (e instanceof ApiError && [401, 403].includes(e.status)) {
          return { cart: null, pricing: null };
        }
        throw e;
      }
    },
    retry: false,
  });

  const addMutation = useMutation({
    mutationFn: cartApi.addItem,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["cart"] });
      void qc.invalidateQueries({ queryKey: ["checkout-quote"] });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, quantity }: { id: string; quantity: number }) =>
      cartApi.updateItem(id, { quantity }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });

  const removeMutation = useMutation({
    mutationFn: (id: string) => cartApi.removeItem(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });

  const clearMutation = useMutation({
    mutationFn: () => cartApi.clearCart(),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["cart"] });
      void qc.removeQueries({ queryKey: ["checkout-quote"] });
    },
  });

  const addMutateAsync = addMutation.mutateAsync;
  const updateMutateAsync = updateMutation.mutateAsync;
  const removeMutateAsync = removeMutation.mutateAsync;
  const clearMutateAsync = clearMutation.mutateAsync;

  const addItem = useCallback(
    async (payload: AddItemPayload): Promise<AddItemResult> => {
      try {
        await addMutateAsync(payload);
        return { ok: true };
      } catch (e) {
        if (
          e instanceof ApiError &&
          e.status === 409 &&
          (e.code === "CART_BRANCH_CONFLICT" ||
            e.code === "CART_RESTAURANT_CONFLICT" ||
            e.errors?.code?.includes("CART_BRANCH_CONFLICT"))
        ) {
          const meta = (e.envelope?.data ?? null) as ConflictData | null;
          setConflict({ open: true, data: meta, pending: payload });
          return { ok: false, conflict: true };
        }
        throw e;
      }
    },
    [addMutateAsync],
  );

  const clearConflict = useCallback(() => setConflict(emptyConflict), []);

  const confirmReplaceRestaurant = useCallback(async () => {
    if (!conflict.pending) return;
    const pending = conflict.pending;
    try {
      // Explicit clear-then-add (do not rely on replace flag alone).
      await clearMutateAsync();
      await addMutateAsync({ ...pending, replace_restaurant: undefined });
      setConflict(emptyConflict);
      await qc.invalidateQueries({ queryKey: ["cart"] });
    } catch (e) {
      // Keep dialog closed but leave original cart if clear failed mid-flight.
      setConflict(emptyConflict);
      await qc.invalidateQueries({ queryKey: ["cart"] });
      throw e;
    }
  }, [addMutateAsync, clearMutateAsync, conflict.pending, qc]);

  const clearCart = useCallback(async () => {
    await clearMutateAsync();
  }, [clearMutateAsync]);

  const updateQuantity = useCallback(
    async (id: string, quantity: number) => {
      await updateMutateAsync({ id, quantity });
    },
    [updateMutateAsync],
  );

  const removeLine = useCallback(
    async (id: string) => {
      await removeMutateAsync(id);
    },
    [removeMutateAsync],
  );

  const refetch = useCallback(() => {
    void query.refetch();
  }, [query.refetch]);

  const value = useMemo<CartContextValue>(
    () => ({
      cart: query.data?.cart ?? null,
      pricing: query.data?.pricing ?? null,
      itemCount: query.data?.cart?.items.reduce((s, i) => s + i.quantity, 0) ?? 0,
      isLoading: query.isLoading,
      refetch,
      addItem,
      conflict,
      clearConflict,
      confirmReplaceRestaurant,
      updateQuantity,
      removeLine,
      clearCart,
    }),
    [
      query.data,
      query.isLoading,
      refetch,
      addItem,
      conflict,
      clearConflict,
      confirmReplaceRestaurant,
      updateQuantity,
      removeLine,
      clearCart,
    ],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within CartProvider");
  return ctx;
}
