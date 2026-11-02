import { FormErrors } from "@/components/form-errors";
import type { AppPageProps } from "@/types";
import { Link, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/components/page-header";
import { buttonVariants } from "@/components/ui/button";
import { CaseForm } from "@/components/staff/review-forms";
import { useApp } from "@/contexts/app";

export type DetailField = {
  label: string;
  value: string | number | boolean | null;
};
export type RecordLink = { label: string; url: string };
export type CaseTarget = { type: string; id: number; url: string };
export type DetailProps = {
  heading?: "h1" | "h2";
  fields: DetailField[];
  links?: RecordLink[];
  history?: RecordLink[];
  public_links?: RecordLink[];
  case_target?: CaseTarget | null;
  actions?: ReactNode;
  children?: ReactNode;
};
export function DetailFields({
  fields,
  title = "Record details",
}: {
  fields: DetailField[];
  title?: string;
}) {
  const { formatDateTime } = useApp();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <dl className="grid gap-5 px-5 pb-5 sm:grid-cols-2 sm:px-6 sm:pb-6">
        {fields.map((field) => (
          <div key={field.label} className="min-w-0">
            <dt className="text-xs text-muted-foreground">{field.label}</dt>
            <dd className="mt-1.5 text-sm whitespace-pre-wrap break-words">
              {field.value === null || field.value === ""
                ? "—"
                : typeof field.value === "boolean"
                  ? field.value
                    ? "Yes"
                    : "No"
                  : typeof field.value === "string" &&
                      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(field.value)
                    ? formatDateTime(field.value)
                    : String(field.value)}
            </dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}
export function RelatedLinks({ links }: { links: RecordLink[] }) {
  return links.length ? (
    <div className="flex flex-wrap gap-2">
      {links.map((link) => (
        <Link
          key={link.url + link.label}
          href={link.url}
          className={buttonVariants({ variant: "secondary", size: "sm" })}
        >
          {link.label}
        </Link>
      ))}
    </div>
  ) : null;
}
export function DetailScreen({
  fields,
  links = [],
  history = [],
  heading,
  public_links = [],
  case_target,
  actions,
  children,
}: DetailProps) {
  const { page, errors } = usePage<AppPageProps>().props;
  return (
    <div className="space-y-6">
      <PageHeader
        heading={heading}
        title={page.title}
        description="Operational record and related activity."
        actions={actions}
      />
      <FormErrors errors={{ form: errors.form }} />
      <DetailFields fields={fields} />
      <RelatedLinks links={links} />
      {public_links.length > 0 && (
        <Card className="flex flex-wrap gap-3 p-5">
          {public_links.map((link) => (
            <a
              className="text-sm text-primary underline"
              key={link.url}
              href={link.url}
              target="_blank"
              rel="noopener noreferrer"
            >
              {link.label}
            </a>
          ))}
        </Card>
      )}
      {history.length > 0 && (
        <Card className="space-y-4 p-5">
          <h2 className="font-semibold">Recent moderation history</h2>
          <RelatedLinks links={history} />
        </Card>
      )}
      {children}
      {case_target && <CaseForm target={case_target} />}
    </div>
  );
}
