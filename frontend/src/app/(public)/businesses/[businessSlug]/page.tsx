import { LiveBusinessPage } from "@/features/public-business/components/live-business-page";

export default async function BusinessPage({
  params,
}: {
  params: Promise<{ businessSlug: string }>;
}) {
  const { businessSlug } = await params;
  return <LiveBusinessPage businessSlug={businessSlug} />;
}
