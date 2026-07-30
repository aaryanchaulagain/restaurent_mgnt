"use client";

import { use } from "react";
import { LiveRestaurantPage } from "@/features/public-restaurants/components/live-restaurant-page";

export default function RestaurantDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = use(params);
  return <LiveRestaurantPage slug={slug} />;
}
