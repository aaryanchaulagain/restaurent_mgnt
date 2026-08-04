import type { NextConfig } from "next";
import path from "node:path";

const nextConfig: NextConfig = {
  // Keep Turbopack scoped to this app (avoids parent lockfiles / monorepo confusion).
  turbopack: {
    root: path.join(__dirname),
  },
  experimental: {
    optimizePackageImports: ["lucide-react"],
  },
  productionBrowserSourceMaps: false,
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "X-Frame-Options", value: "SAMEORIGIN" },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=(self)",
          },
          // Report-only CSP first — tighten after verifying Stripe.js, images, fonts, API.
          {
            key: "Content-Security-Policy-Report-Only",
            value: [
              "default-src 'self'",
              "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com",
              "style-src 'self' 'unsafe-inline'",
              "img-src 'self' data: blob: https: http://localhost:8000 http://127.0.0.1:8000",
              "font-src 'self' data:",
              "connect-src 'self' https://api.stripe.com https://*.stripe.com http://localhost:8000 http://127.0.0.1:8000",
              "frame-src https://js.stripe.com https://hooks.stripe.com",
              "object-src 'none'",
              "base-uri 'self'",
              "frame-ancestors 'self'",
            ].join("; "),
          },
        ],
      },
    ];
  },
  // Same-origin proxy so a single Cloudflare Tunnel can reach Laravel too.
  async rewrites() {
    const api = process.env.BACKEND_ORIGIN?.replace(/\/$/, "") || "http://127.0.0.1:8000";
    return [
      { source: "/api/:path*", destination: `${api}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${api}/sanctum/:path*` },
      { source: "/storage/:path*", destination: `${api}/storage/:path*` },
    ];
  },
  images: {
    formats: ["image/avif", "image/webp"],
    deviceSizes: [640, 750, 828, 1080, 1200, 1920],
    imageSizes: [64, 96, 128, 256, 384],
    minimumCacheTTL: 60 * 60 * 24,
    remotePatterns: [
      {
        protocol: "https",
        hostname: "images.unsplash.com",
      },
      {
        protocol: "http",
        hostname: "localhost",
        port: "8000",
        pathname: "/storage/**",
      },
      {
        protocol: "http",
        hostname: "127.0.0.1",
        port: "8000",
        pathname: "/storage/**",
      },
    ],
  },
};

export default nextConfig;
