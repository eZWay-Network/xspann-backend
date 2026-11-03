import type { ReactNode } from "react";
import { DetailFields, type DetailField } from "@/components/staff/detail";
import { Alert } from "@/components/ui/alert";
export type ConfigurationProps = {
  sections: { title: string; fields: DetailField[] }[];
  children?: ReactNode;
};

export function Configuration({ sections, children }: ConfigurationProps) {
  return (
    <div className="space-y-6">
      <Alert variant="info">
        Read-only deployment configuration. Changes require the normal reviewed
        deployment process.
      </Alert>
      {children}
      {sections.map((section) => (
        <DetailFields
          key={section.title}
          title={section.title}
          fields={section.fields}
        />
      ))}
    </div>
  );
}
