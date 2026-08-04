import { LiveBranchPage } from "@/features/public-business/components/live-branch-page";

export default async function BusinessBranchPage({
  params,
}: {
  params: Promise<{ businessSlug: string; branchPublicId: string }>;
}) {
  const { businessSlug, branchPublicId } = await params;
  return <LiveBranchPage businessSlug={businessSlug} branchPublicId={branchPublicId} />;
}
