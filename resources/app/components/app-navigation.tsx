import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import type { NavigationGroup } from "@/types";
import { cn } from "@/lib/utils";
import {
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
} from "./ui/accordion";

export function AppNavigation({
  placement,
  onNavigate,
}: {
  placement: NavigationGroup["placement"];
  onNavigate?: () => void;
}) {
  const { menu, activeMenu, activePage } = useApp();
  const [expanded, setExpanded] = useState<string[]>([]);
  useEffect(() => {
    const active = activeMenu?.children ? activeMenu : undefined;
    if (active)
      setExpanded((previous) =>
        previous.includes(active.title)
          ? previous
          : [...previous, active.title],
      );
  }, [activeMenu]);
  return (
    <nav
      aria-label={
        placement === "main" ? "Main navigation" : "Workspace navigation"
      }
      className="space-y-7"
    >
      {menu
        .filter((group) => group.placement === placement)
        .map((group) => (
          <div key={group.label}>
            <p className="sr-only">{group.label}</p>
            <Accordion
              multiple
              value={expanded}
              onValueChange={(value) => setExpanded(value as string[])}
              className="space-y-1"
            >
              {group.items.map((item) =>
                item.children ? (
                  <AccordionItem key={item.title} value={item.title}>
                    <AccordionTrigger
                      className={cn(activeMenu === item && "text-foreground")}
                    >
                      <item.icon className="size-[18px]" strokeWidth={1.7} />
                      {item.title}
                    </AccordionTrigger>
                    <AccordionContent>
                      <div className="my-1 ml-5 space-y-1 border-l pl-3">
                        {item.children.map((child) => (
                          <Link
                            key={child.path}
                            href={child.path}
                            onClick={onNavigate}
                            aria-current={
                              activePage?.path === child.path
                                ? "page"
                                : undefined
                            }
                            className={cn(
                              "focus-ring block rounded-md px-3 py-2 text-xs transition-colors",
                              activePage?.path === child.path
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
                    key={item.title}
                    href={item.path!}
                    onClick={onNavigate}
                    aria-current={activeMenu === item ? "page" : undefined}
                    className={cn(
                      "nav-link",
                      activeMenu === item &&
                        "bg-accent text-accent-foreground hover:bg-accent hover:text-accent-foreground",
                    )}
                  >
                    <item.icon className="size-[18px]" strokeWidth={1.7} />
                    <span>{item.title}</span>
                  </Link>
                ),
              )}
            </Accordion>
          </div>
        ))}
    </nav>
  );
}
