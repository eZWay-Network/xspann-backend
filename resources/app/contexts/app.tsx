import { usePage } from "@inertiajs/react";
import { icons } from "@/lib/icons";
import type { AppPageProps, NavigationItem } from "@/types";
export function useApp() {
  const { props, url } = usePage<AppPageProps>();
  const pathname = new URL(url, window.location.origin).pathname.replace(
    /\/$/,
    "",
  );
  const isActive = (item: NavigationItem) =>
    Boolean(
      item.path &&
      new URL(item.path, window.location.origin).pathname.replace(/\/$/, "") ===
        pathname,
    );
  const decorate = (item: NavigationItem) => ({
    ...item,
    icon: icons[item.icon],
    children: item.children?.map((child) => ({
      ...child,
      icon: icons[child.icon],
    })),
  });
  const menu = props.navigation.map((group) => ({
    ...group,
    items: group.items.map(decorate),
  }));
  const activeMenu = menu
    .flatMap((group) => group.items)
    .find((item) =>
      item.path
        ? new URL(item.path, window.location.origin).pathname.replace(
            /\/$/,
            "",
          ) === pathname
        : item.children?.some(
            (child) =>
              child.path &&
              new URL(child.path, window.location.origin).pathname === pathname,
          ),
    );
  const activePage =
    activeMenu?.children?.find(
      (child) =>
        child.path &&
        new URL(child.path, window.location.origin).pathname === pathname,
    ) ?? activeMenu;
  const menuLinks = menu.flatMap((group) =>
    group.items.flatMap((item) =>
      item.children
        ? item.children.map((child) => ({
            title: child.title,
            icon: child.icon,
            path: child.path!,
            parent: item.title,
          }))
        : [
            {
              title: item.title,
              icon: item.icon,
              path: item.path!,
              parent: group.label,
            },
          ],
    ),
  );
  const settingsNavigation = menuLinks.filter(
    (item) => item.parent === "Settings",
  );
  const formatDateTime = (value: string, seconds = false) =>
    new Intl.DateTimeFormat(props.preferences.locale, {
      dateStyle: "medium",
      timeStyle: seconds ? "medium" : "short",
      timeZone: props.preferences.timezone,
    }).format(new Date(value));

  return {
    ...props,
    pathname,
    pageTitle: props.page.title,
    menu,
    activeMenu,
    activePage,
    menuLinks,
    settingsNavigation,
    isActive,
    formatDateTime,
  };
}
