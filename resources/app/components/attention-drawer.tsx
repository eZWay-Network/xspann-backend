import { Link } from "@inertiajs/react";
import { Bell, ArrowUpRight } from "lucide-react";
import { useApp } from "@/contexts/app";
import {
  Drawer,
  DrawerContent,
  DrawerTitle,
  DrawerDismiss,
} from "@/components/ui/drawer";
import { EmptyState } from "@/components/empty-state";
export function AttentionItems({ onNavigate }: { onNavigate?: () => void }) {
  const { attention } = useApp();
  return attention.length ? (
    <ul className="divide-y">
      {attention.map((item) => (
        <li key={item.key}>
          <Link
            href={item.url}
            onClick={onNavigate}
            className="focus-ring flex items-start gap-3 p-5 transition-colors hover:bg-muted/40"
          >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-primary">
              <Bell className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium">
                {item.title}{" "}
                <span className="ml-1 text-muted-foreground">{item.count}</span>
              </p>
              <p className="mt-1 text-xs leading-5 text-muted-foreground">
                {item.description}
              </p>
            </div>
            <ArrowUpRight className="mt-1 size-4 text-muted-foreground" />
          </Link>
        </li>
      ))}
    </ul>
  ) : (
    <EmptyState
      icon={Bell}
      title="You’re all caught up"
      description="No staff account items need your attention right now."
    />
  );
}
export function AttentionDrawer({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent side="right" className="bg-background">
        <div className="shrink-0 border-b bg-card px-5 pt-6 pb-5">
          <DrawerTitle>Attention</DrawerTitle>
          <DrawerDismiss />
          <p className="mt-2 pr-4 text-xs text-muted-foreground">
            Items in your authorized workspace.
          </p>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
          <AttentionItems onNavigate={() => onOpenChange(false)} />
        </div>
      </DrawerContent>
    </Drawer>
  );
}
