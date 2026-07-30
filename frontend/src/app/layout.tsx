import type { Metadata } from "next";
import { DM_Serif_Display, Plus_Jakarta_Sans } from "next/font/google";
import { ToastProvider } from "@/components/ui/navigation";
import { AppProviders } from "@/components/providers";
import "./globals.css";

const display = DM_Serif_Display({
  weight: "400",
  subsets: ["latin"],
  variable: "--font-display-loaded",
});

const body = Plus_Jakarta_Sans({
  subsets: ["latin"],
  variable: "--font-body-loaded",
});

export const metadata: Metadata = {
  title: {
    default: "Suvakamana",
    template: "%s · Suvakamana",
  },
  description:
    "Exceptional food, delivered from restaurants you love. Discover local flavours and premium restaurants.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${display.variable} ${body.variable} h-full antialiased`}
    >
      <body
        className="min-h-full flex flex-col overflow-x-hidden"
        style={
          {
            "--font-display": "var(--font-display-loaded), Georgia, serif",
            "--font-body": "var(--font-body-loaded), system-ui, sans-serif",
          } as React.CSSProperties
        }
      >
        <AppProviders>
          <ToastProvider>{children}</ToastProvider>
        </AppProviders>
      </body>
    </html>
  );
}
