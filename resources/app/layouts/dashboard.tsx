import { Suspense, useEffect, useRef, useState, type ReactNode } from "react";
import { router, Head } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import { AttentionDrawer } from "@/components/attention-drawer";
import { AppSidebar } from "@/components/app-sidebar";
import { AppHeader } from "@/components/app-header";
import { NavigationSearch } from "@/components/navigation-search";
import PageLoader from "@/components/loader";
import {
  Drawer,
  DrawerContent,
  DrawerTitle,
  DrawerDismiss,
} from "@/components/ui/drawer";
export default function DashboardLayout({ children }: { children: ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [attentionOpen, setAttentionOpen] = useState(false);
  const content = useRef<HTMLElement>(null);
  const { auth, attention, urls, pageTitle, preferences } = useApp();
  useEffect(
    () =>
      router.on("success", () => {
        setMobileOpen(false);
        setSearchOpen(false);
        setAttentionOpen(false);
        content.current?.focus({ preventScroll: true });
      }),
    [],
  );
  useEffect(() => {
    const handle = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key === "k") {
        event.preventDefault();
        setSearchOpen((open) => !open);
      }
    };
    window.addEventListener("keydown", handle);
    return () => window.removeEventListener("keydown", handle);
  }, []);
  useEffect(() => {
    if (!auth) return;
    const expire = () => {
      if (Date.now() >= new Date(auth.mfa_expires_at).getTime()) {
        setAttentionOpen(false);
        setSearchOpen(false);
        setMobileOpen(false);
        window.location.assign(urls.challenge);
      }
    };
    const timer = window.setTimeout(
      expire,
      Math.max(0, new Date(auth.mfa_expires_at).getTime() - Date.now()) + 100,
    );
    window.addEventListener("focus", expire);
    return () => {
      clearTimeout(timer);
      window.removeEventListener("focus", expire);
    };
  }, [auth, urls.challenge]);
  return (
    <div className="relative h-dvh overflow-hidden">
      <Head title={`${pageTitle} · XPN Social`} />
      <a
        href="#main-content"
        className="fixed top-2 left-2 z-60 -translate-y-20 rounded-md bg-foreground px-4 py-2 text-card focus:translate-y-0"
      >
        Skip to content
      </a>
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-60 border-r bg-card lg:block">
        <AppSidebar />
      </aside>
      <Drawer open={mobileOpen} onOpenChange={setMobileOpen}>
        <DrawerContent side="left">
          <DrawerTitle className="sr-only">Navigation</DrawerTitle>
          <DrawerDismiss />
          <AppSidebar onNavigate={() => setMobileOpen(false)} />
        </DrawerContent>
      </Drawer>
      <div className="flex h-dvh min-w-0 flex-col lg:pl-60">
        <AppHeader
          onMenu={() => setMobileOpen(true)}
          onSearch={() => setSearchOpen(true)}
          onNotifications={() => setAttentionOpen(true)}
          unreadCount={preferences.attention_enabled ? attention.length : 0}
          onLogout={() => router.post(urls.logout)}
        />
        <main
          ref={content}
          id="main-content"
          tabIndex={-1}
          scroll-region=""
          className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain outline-none"
        >
          <div className="mx-auto w-full max-w-370 p-4 sm:p-8 xl:p-10">
            <Suspense fallback={<PageLoader fullPage={false} />}>
              {children}
            </Suspense>
          </div>
        </main>
      </div>
      <AttentionDrawer open={attentionOpen} onOpenChange={setAttentionOpen} />
      <NavigationSearch open={searchOpen} onOpenChange={setSearchOpen} />
    </div>
  );
}
