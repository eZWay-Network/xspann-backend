import { Link } from "@inertiajs/react";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/components/page-header";
import { useApp } from "@/contexts/app";

type Props = {
  queues: { title: string; count: number; url: string }[];
  recent: {
    title: string;
    items: {
      id: number;
      label: string;
      reason: string;
      created_at: string;
      url: string;
    }[];
  }[];
};
export default function Dashboard({ queues, recent }: Props) {
  const { formatDateTime } = useApp();
  return (
    <div className="space-y-6">
      <PageHeader
        title="Dashboard"
        description="Reports, appeals and recent moderation actions."
      />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {queues.map((queue) => (
          <Link
            key={queue.title}
            href={queue.url}
            className="focus-ring rounded-lg"
          >
            <Card className="h-full p-5">
              <p className="text-sm text-muted-foreground">{queue.title}</p>
              <p className="mt-3 text-3xl font-semibold tabular-nums">
                {queue.count}
              </p>
            </Card>
          </Link>
        ))}
      </div>
      <div className="grid items-start gap-6 lg:grid-cols-2">
        {recent.map((section) => (
          <Card key={section.title}>
            <CardHeader>
              <CardTitle>
                Recent {section.title.toLowerCase()} actions
              </CardTitle>
            </CardHeader>
            <div className="divide-y px-5 pb-5">
              {section.items.length ? (
                section.items.map((item) => (
                  <Link key={item.id} href={item.url} className="block py-4">
                    <p className="text-sm font-medium">{item.label}</p>
                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                      {item.reason}
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                      {formatDateTime(item.created_at)}
                    </p>
                  </Link>
                ))
              ) : (
                <p className="py-4 text-sm text-muted-foreground">
                  No recent actions.
                </p>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
