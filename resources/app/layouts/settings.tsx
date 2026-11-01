import type { ReactNode } from "react";
import { PageHeader } from "@/components/page-header";
import { SettingsNavigation } from "@/components/settings-navigation";
export default function SettingsLayout({ children }: { children: ReactNode }) {
  return (
    <div className="page-stack max-w-6xl">
      <PageHeader
        title="Settings"
        description="Manage your account and workspace preferences."
      />
      <SettingsNavigation />
      <div className="pt-3">{children}</div>
    </div>
  );
}
