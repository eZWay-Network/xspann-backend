import { useState, useMemo } from "react";
import { Link } from "@inertiajs/react";
import {
  ShieldAlert,
  Clock3,
  Eye,
  Scale,
  ArrowUpRight,
  ArrowRight,
  UserX,
  Trash2,
  Lock,
  Archive,
  ShieldCheck,
  AlertTriangle,
  UserRound,
  FileText,
  Activity,
  Layers,
  Sparkles,
} from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useApp } from "@/contexts/app";
import { cn } from "@/lib/utils";

type ModerationItem = {
  id: number;
  label: string;
  reason: string;
  created_at: string;
  url: string;
};

type Props = {
  queues: { title: string; count: number; url: string }[];
  recent: {
    title: string;
    items: ModerationItem[];
  }[];
};

function getQueueConfig(title: string) {
  const normalized = title.toLowerCase();
  if (normalized.includes("open")) {
    return {
      icon: ShieldAlert,
      iconColor: "text-destructive",
      iconBg: "bg-destructive-soft",
      borderHover: "hover:border-destructive/40",
      urgency: "Needs Triage",
      tone: "danger" as const,
    };
  }
  if (normalized.includes("awaiting")) {
    return {
      icon: Clock3,
      iconColor: "text-warning",
      iconBg: "bg-warning-soft",
      borderHover: "hover:border-warning/40",
      urgency: "First Look",
      tone: "warning" as const,
    };
  }
  if (normalized.includes("in review")) {
    return {
      icon: Eye,
      iconColor: "text-primary",
      iconBg: "bg-accent",
      borderHover: "hover:border-primary/40",
      urgency: "Active",
      tone: "info" as const,
    };
  }
  if (normalized.includes("appeal")) {
    return {
      icon: Scale,
      iconColor: "text-purple-600 dark:text-purple-400",
      iconBg: "bg-purple-50 dark:bg-purple-950/40",
      borderHover: "hover:border-purple-400/40",
      urgency: "Escalated",
      tone: "neutral" as const,
    };
  }
  return {
    icon: Activity,
    iconColor: "text-muted-foreground",
    iconBg: "bg-muted",
    borderHover: "hover:border-border",
    urgency: "Queue",
    tone: "neutral" as const,
  };
}

function parseModerationLabel(label: string) {
  const parts = label.split(" · ");
  const action = (parts[0] ?? "Action").trim();
  const target = (parts[1] ?? "").trim();
  const actionLower = action.toLowerCase();

  let tone: "danger" | "warning" | "info" | "neutral" = "neutral";
  let ActionIcon = ShieldCheck;

  if (actionLower.includes("ban") || actionLower.includes("remove")) {
    tone = "danger";
    ActionIcon = actionLower.includes("ban") ? UserX : Trash2;
  } else if (
    actionLower.includes("suspend") ||
    actionLower.includes("quarantine")
  ) {
    tone = "warning";
    ActionIcon = actionLower.includes("suspend") ? Clock3 : AlertTriangle;
  } else if (actionLower.includes("restrict")) {
    tone = "info";
    ActionIcon = Lock;
  } else if (
    actionLower.includes("soft_delete") ||
    actionLower.includes("delete")
  ) {
    tone = "neutral";
    ActionIcon = Archive;
  }

  const isUserTarget = target.toLowerCase().startsWith("user");

  return {
    action,
    target,
    tone,
    ActionIcon,
    TargetIcon: isUserTarget ? UserRound : FileText,
  };
}

export default function Dashboard({ queues, recent }: Props) {
  const { auth, workspace, urls, formatDateTime } = useApp();
  const [activeTab, setActiveTab] = useState<string>("all");

  const accountsSection = recent.find(
    (s) => s.title.toLowerCase() === "accounts",
  );
  const contentSection = recent.find(
    (s) => s.title.toLowerCase() === "content",
  );

  const allItems = useMemo(() => {
    const list: (ModerationItem & { category: string })[] = [];
    for (const sec of recent) {
      for (const item of sec.items) {
        list.push({ ...item, category: sec.title });
      }
    }
    return list.sort((a, b) => b.id - a.id);
  }, [recent]);

  const activeItems = useMemo(() => {
    if (activeTab === "accounts") {
      return (accountsSection?.items ?? []).map((item) => ({
        ...item,
        category: "Accounts",
      }));
    }
    if (activeTab === "content") {
      return (contentSection?.items ?? []).map((item) => ({
        ...item,
        category: "Content",
      }));
    }
    return allItems;
  }, [activeTab, accountsSection, contentSection, allItems]);

  const totalActionsCount = allItems.length;
  const accountsCount = accountsSection?.items.length ?? 0;
  const contentCount = contentSection?.items.length ?? 0;

  return (
    <div className="space-y-8">
      {/* Executive Operations Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
              Moderation Dashboard
            </h1>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700">
              <span className="size-1.5 rounded-full bg-emerald-600 animate-pulse" />
              Live
            </span>
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            Welcome back,{" "}
            <span className="font-medium text-foreground">
              {auth?.user.name}
            </span>
            . Review active queues and enforcement actions across{" "}
            {workspace.name}.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <Link
            href={urls.attention}
            className={buttonVariants({ variant: "secondary", size: "sm" })}
          >
            Staff Attention
            <ArrowRight className="size-3.5" />
          </Link>
          <Link
            href="/advanced/overview"
            className={buttonVariants({ variant: "primary", size: "sm" })}
          >
            <Sparkles className="size-3.5" />
            Workspace Analytics
          </Link>
        </div>
      </div>

      {/* Moderation Triage Queue KPI Grid */}
      <section aria-label="Moderation Queues">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Triage & Review Queues
          </h2>
          <span className="text-xs text-muted-foreground">
            {queues.reduce((acc, q) => acc + q.count, 0)} total pending items
          </span>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {queues.map((queue) => {
            const config = getQueueConfig(queue.title);
            const Icon = config.icon;
            const hasItems = queue.count > 0;

            return (
              <Link
                key={queue.title}
                href={queue.url}
                className={cn(
                  "group focus-ring relative flex flex-col justify-between overflow-hidden rounded-xl border border-border bg-card p-5 shadow-card transition-all hover:shadow-md",
                  config.borderHover,
                )}
              >
                <div>
                  <div className="flex items-center justify-between gap-2">
                    <div
                      className={cn(
                        "flex size-10 shrink-0 items-center justify-center rounded-lg transition-transform group-hover:scale-105",
                        config.iconBg,
                        config.iconColor,
                      )}
                    >
                      <Icon className="size-5" strokeWidth={1.8} />
                    </div>
                    <Badge tone={hasItems ? config.tone : "neutral"}>
                      {hasItems ? config.urgency : "Clear"}
                    </Badge>
                  </div>

                  <div className="mt-5">
                    <p className="text-3xl font-bold tracking-tight text-foreground tabular-nums sm:text-4xl">
                      {queue.count.toLocaleString()}
                    </p>
                    <p className="mt-1 text-sm font-medium text-foreground/80">
                      {queue.title}
                    </p>
                  </div>
                </div>

                <div className="mt-4 flex items-center justify-between border-t border-border/60 pt-3 text-xs font-medium text-muted-foreground group-hover:text-primary transition-colors">
                  <span>Open queue</span>
                  <ArrowUpRight className="size-3.5 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                </div>
              </Link>
            );
          })}
        </div>
      </section>

      {/* Recent Moderation Actions Section */}
      <section aria-label="Recent Moderation Actions">
        <Card className="overflow-hidden">
          <CardHeader className="flex-col items-start gap-4 border-b border-border/80 bg-muted/20 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div className="flex items-center gap-2">
                <CardTitle className="text-base font-semibold">
                  Recent Enforcement Actions
                </CardTitle>
                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                  {totalActionsCount} logged
                </span>
              </div>
              <CardDescription className="mt-0.5 text-xs text-muted-foreground">
                Audit trail of moderation decisions across user accounts and
                uploaded content.
              </CardDescription>
            </div>

            {/* Filter Tabs using Base UI */}
            <Tabs
              value={activeTab}
              onValueChange={(val) => setActiveTab(String(val))}
            >
              <TabsList aria-label="Filter moderation actions by scope">
                <TabsTrigger value="all">All ({totalActionsCount})</TabsTrigger>
                <TabsTrigger value="accounts">
                  Accounts ({accountsCount})
                </TabsTrigger>
                <TabsTrigger value="content">
                  Content ({contentCount})
                </TabsTrigger>
              </TabsList>
            </Tabs>
          </CardHeader>

          <div className="divide-y divide-border/60">
            {activeItems.length > 0 ? (
              activeItems.map((item) => {
                const { action, target, tone, ActionIcon, TargetIcon } =
                  parseModerationLabel(item.label);

                return (
                  <Link
                    key={item.id}
                    href={item.url}
                    className="group focus-ring flex flex-col gap-3 p-4 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:p-5"
                  >
                    <div className="flex min-w-0 items-start gap-3.5">
                      <div
                        className={cn(
                          "mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border",
                          tone === "danger" &&
                            "border-destructive/20 bg-destructive-soft text-destructive",
                          tone === "warning" &&
                            "border-warning/20 bg-warning-soft text-warning",
                          tone === "info" &&
                            "border-primary/20 bg-accent text-primary",
                          tone === "neutral" &&
                            "border-border bg-muted/60 text-muted-foreground",
                        )}
                      >
                        <ActionIcon className="size-4" strokeWidth={1.8} />
                      </div>

                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge tone={tone} className="capitalize">
                            {action}
                          </Badge>

                          {target && (
                            <span className="inline-flex items-center gap-1 rounded bg-muted/70 px-2 py-0.5 font-mono text-[11px] font-medium text-muted-foreground">
                              <TargetIcon className="size-3" />
                              {target}
                            </span>
                          )}

                          <span className="text-[11px] text-muted-foreground/80 sm:hidden">
                            {formatDateTime(item.created_at)}
                          </span>
                        </div>

                        {item.reason && (
                          <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed text-foreground/90">
                            <span className="text-muted-foreground">
                              Reason:{" "}
                            </span>
                            {item.reason}
                          </p>
                        )}
                      </div>
                    </div>

                    <div className="flex shrink-0 items-center justify-between gap-4 sm:flex-col sm:items-end sm:justify-center">
                      <span className="hidden items-center gap-1.5 text-xs text-muted-foreground sm:inline-flex">
                        <Clock3 className="size-3.5 text-muted-foreground/70" />
                        {formatDateTime(item.created_at)}
                      </span>
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-primary transition-transform group-hover:translate-x-1">
                        View details
                        <ArrowRight className="size-3.5" />
                      </span>
                    </div>
                  </Link>
                );
              })
            ) : (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-muted/60 text-muted-foreground">
                  <ShieldCheck
                    className="size-6 text-muted-foreground/70"
                    strokeWidth={1.5}
                  />
                </div>
                <h3 className="mt-3 text-sm font-semibold text-foreground">
                  No actions in this scope
                </h3>
                <p className="mt-1 text-xs text-muted-foreground">
                  No moderation enforcement records were logged for {activeTab}.
                </p>
              </div>
            )}
          </div>
        </Card>
      </section>

      {/* Operational Quick Access Dock */}
      <section
        aria-label="Operations Quick Links"
        className="rounded-xl border border-border/80 bg-muted/30 p-4 sm:p-5"
      >
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-lg bg-card border border-border text-primary shadow-xs">
              <Layers className="size-4.5" />
            </div>
            <div>
              <p className="text-xs font-semibold text-foreground">
                Operational Shortcuts
              </p>
              <p className="text-[11px] text-muted-foreground">
                Rapidly navigate to authorized administrative registries.
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Link
              href={urls.attention}
              className="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-card hover:bg-muted/70 transition-colors"
            >
              Needs Attention
              <ArrowUpRight className="size-3 text-muted-foreground" />
            </Link>
            <Link
              href="/advanced/overview"
              className="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-card hover:bg-muted/70 transition-colors"
            >
              Metrics Overview
              <ArrowUpRight className="size-3 text-muted-foreground" />
            </Link>
            <Link
              href={urls.profile}
              className="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-card hover:bg-muted/70 transition-colors"
            >
              Staff Profile
              <ArrowUpRight className="size-3 text-muted-foreground" />
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
