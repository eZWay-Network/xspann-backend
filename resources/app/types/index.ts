import type { PageProps } from "@inertiajs/core";
export type StaffRole =
  | "admin"
  | "moderator"
  | "support"
  | "promotion_operator"
  | "finance"
  | "operations"
  | "analyst";
export type IconName =
  | "dashboard"
  | "bell"
  | "settings"
  | "user"
  | "users"
  | "shield"
  | "artist"
  | "video"
  | "flag"
  | "megaphone"
  | "activity"
  | "clock";
export type NavigationItem = {
  title: string;
  icon: IconName;
  path?: string;
  children?: NavigationItem[];
};
export type NavigationGroup = {
  label: string;
  placement: "main" | "bottom";
  items: NavigationItem[];
};
export type AttentionItem = {
  observed_at: string;
  key: string;
  title: string;
  description: string;
  count: number;
  severity: "info" | "warning";
  url: string;
};
export interface AppPageProps extends PageProps {
  auth: {
    user: {
      id: number;
      name: string;
      username: string;
      avatar_url: string | null;
    };
    role: StaffRole;
    permissions: string[];
    mfa_expires_at: string;
    session_expires_at: string;
  } | null;
  navigation: NavigationGroup[];
  workspace: { name: string; support_url: string | null };
  csrf_token: string;
  page: { title: string; breadcrumbs: { title: string; url: string | null }[] };
  environment: { app: string; billing: "test" | "live" };
  preferences: { timezone: string; locale: string; attention_enabled: boolean };
  attention: AttentionItem[];
  urls: Record<
    | "dashboard"
    | "attention"
    | "profile"
    | "security"
    | "logout"
    | "challenge"
    | "login",
    string
  >;
}
export type Metric = {
  label: string;
  value: number;
  detail: string;
  icon: IconName;
  comparison: null;
};
export type OverviewData = {
  metrics: Metric[];
  chart: {
    title: string;
    definition: string;
    series: { date: string; value: number }[];
    total: number;
    ceiling: number;
    availability: string;
    comparison: null;
    unit: string;
  };
  distribution: {
    title: string;
    items: { label: string; value: number; percent: number }[];
    total: number;
  };
  generated_at: string;
  bounds: { from: string; until: string };
  data_since: string | null;
  comparison_note: string;
};
