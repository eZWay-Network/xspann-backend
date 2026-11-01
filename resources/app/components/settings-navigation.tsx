import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import { cn } from "@/lib/utils";
export function SettingsNavigation() {
  const { settingsNavigation, pathname } = useApp();
  return (
    <nav
      aria-label="Settings navigation"
      className="-mb-px flex gap-6 overflow-x-auto border-b"
    >
      {settingsNavigation.map((item) => {
        const active =
          new URL(item.path, window.location.origin).pathname === pathname;
        return (
          <Link
            key={item.path}
            href={item.path}
            aria-current={active ? "page" : undefined}
            className={cn(
              "focus-ring flex shrink-0 items-center gap-2 border-b-2 px-0.5 pt-1 pb-4 text-[13px] font-medium transition-colors",
              active
                ? "border-foreground text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            <item.icon className="size-4" />
            {item.title}
          </Link>
        );
      })}
    </nav>
  );
}
