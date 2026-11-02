import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import Brand from "./brand";
import { AppNavigation, type MenuExpansion } from "./app-navigation";
export function AppSidebar({
  onNavigate,
  ...expansion
}: MenuExpansion & { onNavigate?: () => void }) {
  const { urls } = useApp();
  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex h-16 shrink-0 items-center border-b px-4">
        <Link
          href={urls.dashboard}
          aria-label="XPN Social staff home"
          onClick={onNavigate}
          className="focus-ring rounded-md"
        >
          <Brand />
        </Link>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 py-5">
        <AppNavigation
          {...expansion}
          placement="main"
          onNavigate={onNavigate}
        />
      </div>
      <div className="shrink-0 border-t px-2 py-3">
        <AppNavigation
          {...expansion}
          placement="bottom"
          onNavigate={onNavigate}
        />
      </div>
    </div>
  );
}
