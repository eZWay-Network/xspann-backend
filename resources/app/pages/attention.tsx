import { PageHeader } from "@/components/page-header";
import { Card } from "@/components/ui/card";
import { AttentionItems } from "@/components/attention-drawer";
export default function Attention() {
  return (
    <div className="page-stack">
      <PageHeader
        title="Attention"
        description="Staff account items that need a look, scoped to your access."
      />
      <Card className="max-w-4xl overflow-hidden">
        <AttentionItems />
      </Card>
    </div>
  );
}
