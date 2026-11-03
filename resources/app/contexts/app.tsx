import { useMemo } from "react";
import { usePage } from "@inertiajs/react";
import { repeatedTimeOffset, wallClock } from "@/lib/staff-dates";
import { icons } from "@/lib/icons";
import type { AppPageProps, NavigationGroup, NavigationItem } from "@/types";

function pathOf(url: string) {
  return (
    new URL(url, window.location.origin).pathname.replace(/\/$/, "") || "/"
  );
}

function projectNavigation(groups: NavigationGroup[]) {
  const decorate = (item: NavigationItem) => ({
    ...item,
    icon: icons[item.icon],
  });
  const menu = groups.map((group) => ({
    ...group,
    items: group.items.map((item) => ({
      ...decorate(item),
      children: item.children?.map(decorate),
    })),
  }));
  const menuLinks = menu.flatMap((group) =>
    group.items.flatMap((item) =>
      (item.children ?? [item]).map((link) => ({
        key: link.key,
        title: link.title,
        icon: link.icon,
        path: link.path!,
        pathname: pathOf(link.path!),
        parentKey: item.key,
        parent:
          group.placement === "settings"
            ? `Settings · ${group.label}`
            : group.label,
      })),
    ),
  );
  return {
    menu,
    menuLinks,
    sections: menu.filter((group) => group.placement === "settings"),
  };
}

export function useApp() {
  const { props, url } = usePage<AppPageProps>();
  const pathname = pathOf(url);
  const { menu, menuLinks, sections } = useMemo(
    () => projectNavigation(props.navigation),
    [props.navigation],
  );
  const owner = props.page.navigation_owner
    ? pathOf(props.page.navigation_owner)
    : pathname;
  const activePage = menuLinks
    .filter(
      (link) =>
        owner === link.pathname ||
        (link.pathname !== "/" && owner.startsWith(link.pathname + "/")),
    )
    .sort((a, b) => b.pathname.length - a.pathname.length)[0];
  const section = sections.find((group) =>
    group.items.some((item) => item.key === activePage?.key),
  );
  const activeMenuKey = section ? "staff.settings" : activePage?.parentKey;
  const formatDateTime = (value: string, seconds = false) => {
    const instant = new Date(value);
    const displayed = new Intl.DateTimeFormat(props.preferences.locale, {
      dateStyle: "medium",
      timeStyle: seconds ? "medium" : "short",
      timeZone: "UTC",
    }).format(wallClock(instant.getTime(), props.clock));
    return displayed + repeatedTimeOffset(instant, props.clock);
  };
  return {
    ...props,
    pathname,
    pageTitle: props.page.title,
    menu,
    menuLinks,
    sections,
    section,
    activePage,
    activeMenuKey,
    formatDateTime,
  };
}
