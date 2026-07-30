"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { restaurantApi } from "../api/restaurant-api";
import type { RestaurantProfile } from "../types";

export function useRestaurantProfile() {
  const restaurantKey =
    typeof window !== "undefined"
      ? window.localStorage.getItem("suvakamana_restaurant_context_public_id") ?? "membership"
      : "ssr";

  return useQuery({
    queryKey: ["restaurant", restaurantKey, "profile"],
    queryFn: async () => {
      const res = await restaurantApi.getProfile();
      return res.data.profile;
    },
  });
}

export function useRestaurantChecklist() {
  return useQuery({
    queryKey: ["restaurant", "checklist"],
    queryFn: async () => {
      const res = await restaurantApi.getChecklist();
      return res.data;
    },
  });
}

export function useUpdateRestaurantProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: Partial<RestaurantProfile>) => restaurantApi.updateProfile(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant", "profile"] });
      qc.invalidateQueries({ queryKey: ["restaurant", "checklist"] });
    },
  });
}

export function useActivateRestaurant() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => restaurantApi.activate(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["restaurant"] });
    },
  });
}

export function useRestaurantMenuItems() {
  return useQuery({
    queryKey: ["restaurant", "menu-items"],
    queryFn: async () => {
      const res = await restaurantApi.listMenuItems();
      return res.data.items;
    },
  });
}
