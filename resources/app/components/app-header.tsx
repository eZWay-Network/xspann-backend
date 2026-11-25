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
  const { pageTitle, workspace, auth } = useApp();

  return (
    <header className="z-30 flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-card px-4 sm:px-6 lg:px-8">
      <div className="flex min-w-0 items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          className="lg:hidden"
          aria-label="Open navigation"
          onClick={onMenu}
        >
          <Menu />
        </Button>

        <div className="flex items-center gap-2.5 min-w-0">
          <span className="truncate text-base font-semibold text-foreground">
            {pageTitle}
          </span>

          {auth?.role && (
            <span className="hidden sm:inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-muted/60 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground capitalize">
              <span className="size-1.5 rounded-full bg-emerald-500" />
              {auth.role.replaceAll("_", " ")}
            </span>
          )}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-2 sm:gap-3">
        <Button
          variant="ghost"
          size="sm"
          className="gap-2 bg-muted/60 h-9 px-3 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors border border-border/50"
          ref={searchButton}
          onClick={onSearch}
          aria-label="Search navigation"
        >
          <Search className="size-3.5" />
          <span className="hidden text-xs font-normal sm:inline">
            Search staff registry…
          </span>
          <kbd className="keyboard-key hidden sm:inline">
            {/Mac|iPhone|iPad|iPod/.test(navigator.platform) ? "⌘ K" : "Ctrl K"}
          </kbd>
        </Button>

        <Button
          variant="ghost"
          size="icon"
          className="relative text-muted-foreground hover:text-foreground hover:bg-muted/80 transition-colors"
          aria-label={
            unreadCount ? `Attention, ${unreadCount} items` : "Attention"
          }
          onClick={onNotifications}
        >
          <Bell className="size-4.5" />
          {unreadCount > 0 && (
            <span className="absolute top-1 right-1 flex size-2 rounded-full bg-destructive ring-2 ring-card animate-pulse" />
          )}
        </Button>

        {workspace?.support_url && (
          <a
            className="hidden text-xs text-muted-foreground hover:text-primary transition-colors sm:inline"
            href={workspace.support_url}
            target="_blank"
            rel="noopener noreferrer"
          >
            Support
          </a>
        )}

        <span className="mx-1 h-5 w-px bg-border sm:mx-1.5" />
        <AccountMenu onLogout={onLogout} />
      </div>
    </header>
  );
}
