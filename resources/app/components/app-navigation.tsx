import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import { cn } from "@/lib/utils";
import {
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
} from "./ui/accordion";

export type MenuExpansion = {
  expanded: string[];
  onExpand: (keys: string[]) => void;
};

export function AppNavigation({
  placement,
  onNavigate,
  expanded,
  onExpand,
}: MenuExpansion & {
  placement: "main" | "bottom";
  onNavigate?: () => void;
}) {
  const { menu, activeMenuKey, activePage, pathname } = useApp();
  const items = menu
    .filter((group) => group.placement === placement)
    .flatMap((group) => group.items);
  const current = activePage?.pathname === pathname ? "page" : "location";

  return (
    <nav
      aria-label={
        placement === "main" ? "Main navigation" : "Workspace navigation"
      }
    >
      <Accordion
        multiple
        value={expanded}
        onValueChange={onExpand}
        className="space-y-1"
      >
        {items.map((item) =>
          item.children ? (
            <AccordionItem key={item.key} value={item.key}>
              <AccordionTrigger
                className={cn(
                  activeMenuKey === item.key &&
                    (expanded.includes(item.key)
                      ? "text-foreground"
                      : "bg-accent text-accent-foreground"),
                )}
                aria-current={
                  activeMenuKey === item.key && !expanded.includes(item.key)
                    ? "location"
                    : undefined
                }
              >
                <item.icon className="size-[18px]" strokeWidth={1.7} />
                {item.title}
              </AccordionTrigger>
              <AccordionContent>
                <div className="my-1 ml-5 space-y-1 border-l pl-3">
                  {item.children.map((child) => (
                    <Link
                      key={child.key}
                      href={child.path!}
                      onClick={onNavigate}
                      aria-current={
                        activePage?.key === child.key ? current : undefined
                      }
                      className={cn(
                        "focus-ring block rounded-md px-3 py-2 text-xs transition-colors",
                        activePage?.key === child.key
                          ? "bg-accent font-medium text-accent-foreground"
                          : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                      )}
                    >
                      {child.title}
                    </Link>
                  ))}
                </div>
              </AccordionContent>
            </AccordionItem>
          ) : (
            <Link
              key={item.key}
              href={item.path!}
              onClick={onNavigate}
              aria-current={
                activeMenuKey === item.key
                  ? activePage?.key === item.key
                    ? current
                    : "location"
                  : undefined
              }
              className={cn(
                "nav-link",
                activeMenuKey === item.key &&
                  "bg-accent text-accent-foreground hover:bg-accent hover:text-accent-foreground",
              )}
            >
              <item.icon className="size-[18px]" strokeWidth={1.7} />
              <span>{item.title}</span>
            </Link>
          ),
        )}
      </Accordion>
    </nav>
  );
}
