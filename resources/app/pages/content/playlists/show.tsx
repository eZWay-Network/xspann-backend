import { router } from "@inertiajs/react";
import { useState } from "react";
import {
  DetailScreen,
  RelatedLinks,
  type DetailProps,
  type RecordLink,
} from "@/components/staff/detail";
import { RecordsPagination } from "@/components/records-pagination";
import { Card } from "@/components/ui/card";

type Props = DetailProps & {
  items: {
    rows: RecordLink[];
    pagination: { current_page: number; last_page: number; total: number };
    per_page: number;
    url: string;
  };
};

export default function Playlist({ items, ...props }: Props) {
  const [busy, setBusy] = useState(false);
  return (
    <DetailScreen {...props}>
      <Card className="space-y-5 p-5" aria-busy={busy}>
        <h2 className="font-semibold">Playlist items</h2>
        {items.rows.length ? (
          <RelatedLinks links={items.rows} />
        ) : (
          <p className="text-sm text-muted-foreground">
            No items on this page.
          </p>
        )}
        <RecordsPagination
          page={items.pagination.current_page}
          lastPage={items.pagination.last_page}
          total={items.pagination.total}
          busy={busy}
          onChange={(page) =>
            router.get(
              items.url,
              { page, per_page: items.per_page },
              {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
              },
            )
          }
        />
      </Card>
    </DetailScreen>
  );
}
