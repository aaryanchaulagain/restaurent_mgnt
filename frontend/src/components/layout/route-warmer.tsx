"use client";

import { useEffect } from "react";

const WARM_ROUTES = [
  "/restaurants",
  "/cart",
  "/checkout",
  "/contact",
  "/login",
  "/profile",
  "/partner/apply",
  "/orders/track",
];

/**
 * `next dev` compiles routes on demand, and <Link> prefetching is disabled in
 * development, so the first click on any nav item stalls on "Compiling…".
 * Requesting those routes while the browser is idle forces the dev server to
 * compile them up front. No-op in production builds.
 */
export function RouteWarmer() {
  useEffect(() => {
    if (process.env.NODE_ENV !== "development") return;

    const controller = new AbortController();
    let index = 0;

    // Serial, one route at a time: parallel requests would saturate the dev
    // compiler and make the page you are actually on slower.
    const warmNext = () => {
      if (controller.signal.aborted || index >= WARM_ROUTES.length) return;
      const href = WARM_ROUTES[index++];
      void fetch(href, { signal: controller.signal, credentials: "same-origin" })
        .catch(() => undefined)
        .then(() => {
          if (!controller.signal.aborted) window.setTimeout(warmNext, 150);
        });
    };

    const start = window.setTimeout(warmNext, 2000);

    return () => {
      controller.abort();
      window.clearTimeout(start);
    };
  }, []);

  return null;
}
