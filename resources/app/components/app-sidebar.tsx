import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import Brand from "./brand";
import { AppNavigation } from "./app-navigation";
export function AppSidebar({ onNavigate }: { onNavigate?: () => void }) {
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
        <AppNavigation placement="main" onNavigate={onNavigate} />
      </div>
      <div className="max-h-[45%] shrink-0 overflow-y-auto overscroll-contain border-t px-2 py-3">
        <AppNavigation placement="bottom" onNavigate={onNavigate} />
      </div>
    </div>
  );
}
