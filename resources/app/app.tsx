import "./app.css";
import { createInertiaApp, router } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import type { ComponentType, ReactNode } from "react";
import DashboardLayout from "@/layouts/dashboard";
import SettingsLayout from "@/layouts/settings";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ToastProvider, toast } from "@/components/ui/toast";
import type { AppPageProps } from "@/types";

type PageComponent = ComponentType & {
  layout?: (page: ReactNode) => ReactNode;
};
const pages = import.meta.glob<{ default: PageComponent }>("./pages/**/*.tsx");
let csrfToken = "";
router.on("before", (event) => {
  if (event.detail.visit.method !== "get")
    event.detail.visit.headers["X-CSRF-TOKEN"] = csrfToken;
});
router.on("navigate", (event) => {
  csrfToken = String(event.detail.page.props.csrf_token ?? "");
});
router.on("success", (event) => {
  csrfToken = String(event.detail.page.props.csrf_token ?? "");
});
router.on("flash", (event) => {
  if (typeof event.detail.flash.success === "string")
    toast.success(event.detail.flash.success);
});
router.on("networkError", (event) => {
  event.preventDefault();
  toast.error(
    "We couldn’t reach the workspace",
    "Check your connection, then try again. Your action has not been replayed.",
  );
});
void createInertiaApp<AppPageProps>({
  id: "app",
  resolve: async (name) => {
    const loader = pages[`./pages/${name}.tsx`];
    if (!loader) throw new Error(`Unknown page: ${name}`);
    const { default: Page } = await loader();
    if (!name.startsWith("auth/") && name !== "error") {
      Page.layout = (page) => (
        <DashboardLayout>
          {name.startsWith("settings/") ? (
            <SettingsLayout>{page}</SettingsLayout>
          ) : (
            page
          )}
        </DashboardLayout>
      );
    }
    return Page;
  },
  setup({ el, App, props }) {
    csrfToken = props.initialPage.props.csrf_token;
    createRoot(el).render(
      <ToastProvider>
        <TooltipProvider>
          <App {...props} />
        </TooltipProvider>
      </ToastProvider>,
    );
  },
  progress: { color: "var(--primary)", showSpinner: false },
});
