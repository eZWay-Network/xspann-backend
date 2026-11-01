import { Link } from "@inertiajs/react";
import { ArrowRight } from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { buttonVariants } from "@/components/ui/button";
import { AttentionItems } from "@/components/attention-drawer";
import { useApp } from "@/contexts/app";
export function AttentionRecords() {
  const { urls } = useApp();
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <div>
          <CardTitle>Needs your attention</CardTitle>
          <CardDescription>Staff account items you can act on.</CardDescription>
        </div>
        <Link
          href={urls.attention}
          className={buttonVariants({ variant: "secondary", size: "sm" })}
        >
          View all <ArrowRight />
        </Link>
      </CardHeader>
      <AttentionItems />
    </Card>
  );
}
