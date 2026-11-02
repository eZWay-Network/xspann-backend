import type { RefObject } from "react";
import { Bell, Menu, Search } from "lucide-react";
import { useApp } from "@/contexts/app";
import { Button } from "./ui/button";
import { AccountMenu } from "./account-menu";
export function AppHeader({
  searchButton,
  onMenu,
  onSearch,
  onNotifications,
  unreadCount,
  onLogout,
}: {
  searchButton: RefObject<HTMLButtonElement | null>;
  onMenu: () => void;
  onSearch: () => void;
  onNotifications: () => void;
  unreadCount: number;
  onLogout: () => void;
}) {
  const { pageTitle, workspace } = useApp();
  return (
    <header className="z-30 flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-card px-4 sm:px-6 lg:px-8">
      <div className="flex min-w-0 items-center gap-2">
        <Button
          variant="ghost"
          size="icon"
          className="lg:hidden"
          aria-label="Open navigation"
          onClick={onMenu}
        >
          <Menu />
        </Button>
        <span className="truncate text-base font-semibold">{pageTitle}</span>
      </div>
      <div className="flex shrink-0 items-center gap-1.5 sm:gap-3">
        <Button
          variant="ghost"
          size="sm"
          className="gap-2 bg-muted/70 h-9 px-2.5 text-muted-foreground"
          ref={searchButton}
          onClick={onSearch}
          aria-label="Search navigation"
        >
          <Search className="size-4.25!" />
          <span className="hidden text-[0.85rem] font-normal sm:inline">
            Search
          </span>
          <kbd className="keyboard-key hidden sm:inline">
            {/Mac|iPhone|iPad|iPod/.test(navigator.platform) ? "⌘ K" : "Ctrl K"}
          </kbd>
        </Button>
        <Button
          variant="ghost"
          size="icon"
          className="relative"
          aria-label={
            unreadCount ? `Attention, ${unreadCount} items` : "Attention"
          }
          onClick={onNotifications}
        >
          <Bell className="size-5!" />
          {unreadCount > 0 && (
            <span className="absolute top-0.5 right-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[9px] font-semibold text-white">
              {unreadCount > 99 ? "99+" : unreadCount}
            </span>
          )}
        </Button>
        {workspace?.support_url && (
          <a
            className="hidden text-xs text-muted-foreground hover:text-primary sm:inline"
            href={workspace.support_url}
            target="_blank"
            rel="noopener noreferrer"
          >
            Support
          </a>
        )}
        <span className="mx-1 h-6 w-px bg-border sm:mx-2" />
        <AccountMenu onLogout={onLogout} />
      </div>
    </header>
  );
}
