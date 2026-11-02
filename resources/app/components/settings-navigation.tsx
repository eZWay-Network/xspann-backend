import { Link, router } from "@inertiajs/react";
import { ArrowLeft, ChevronRight } from "lucide-react";
import { useApp } from "@/contexts/app";
import { Select } from "@/components/ui/select";
import { cn } from "@/lib/utils";

export function SettingsNavigation() {
  const { sections, section, pathname } = useApp();
  if (!section) return null;
  const [landing, ...tasks] = section.items;
  if (!landing) return null;
  const atLanding =
    new URL(landing.path!, window.location.origin).pathname === pathname;
  return (
    <div className="@container space-y-5">
      <nav aria-label="Settings navigation">
        {sections.length === 1 ? (
          <h2 className="section-title">{section.label}</h2>
        ) : (
          <>
            <div className="hidden gap-6 border-b @min-[24rem]:flex">
              {sections.map((group) => {
                const first = group.items[0]!;
                const active = group.key === section.key;
                return (
                  <Link
                    key={group.key}
                    href={first.path!}
                    aria-current={
                      active ? (atLanding ? "page" : "location") : undefined
                    }
                    className={cn(
                      "focus-ring flex items-center gap-2 border-b-2 px-0.5 pt-1 pb-4 text-[13px] font-medium",
                      active
                        ? "border-foreground"
                        : "border-transparent text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <first.icon className="size-4" />
                    {group.label}
                  </Link>
                );
              })}
            </div>
            <div className="space-y-2 @min-[24rem]:hidden">
              <span className="text-xs font-medium">Settings section</span>
              <Select
                label="Settings section"
                className="w-full"
                value={landing.path}
                onValueChange={(url) => router.visit(url)}
                options={sections.map((group) => ({
                  value: group.items[0]!.path!,
                  label: group.label,
                }))}
              />
            </div>
          </>
        )}
      </nav>
      {atLanding ? (
        <ul aria-label={section.label + " tasks"} className="surface divide-y">
          {tasks.map((item) => (
            <li key={item.key}>
              <Link
                href={item.path!}
                className="focus-ring flex items-center gap-3 rounded-md px-5 py-3 text-sm hover:bg-muted/50"
              >
                <item.icon className="size-4 text-muted-foreground" />
                {item.title}
                <ChevronRight className="ml-auto size-4 text-muted-foreground" />
              </Link>
            </li>
          ))}
        </ul>
      ) : (
        <Link
          href={landing.path!}
          className="focus-ring inline-flex items-center gap-2 rounded text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="size-4" />
          Back to {section.label}
        </Link>
      )}
    </div>
  );
}
