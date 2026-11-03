import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { ExportButton } from "@/components/staff/export-button";
export default function Exports(
  props: RecordsProps & {
    types: ("dashboard" | "finance" | "operations")[];
    store_url: string;
  },
) {
  return (
    <RecordsScreen {...props}>
      <div className="flex flex-wrap gap-4">
        {props.types.map((type) => (
          <div key={type} className="space-y-2">
            <p className="text-xs text-muted-foreground">
              {type === "operations"
                ? "Current operational queues"
                : `${type} · this month`}
            </p>
            <ExportButton url={props.store_url} type={type} />
          </div>
        ))}
      </div>
    </RecordsScreen>
  );
}
