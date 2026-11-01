import { router } from "@inertiajs/react";
import { format, parseISO } from "date-fns";
import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Calendar } from "@/components/ui/calendar";
import { Card } from "@/components/ui/card";
import { DatePicker } from "@/components/ui/date-picker";
import { Button } from "@/components/ui/button";
export default function Placements(props: RecordsProps) {
  const view = String(props.filters.view || "list");
  const day = String(props.filters.day ?? "");
  const visit = (updates: Record<string, string | number>) =>
    router.get(
      props.list_url,
      { ...props.filters, ...updates, page: 1 },
      { preserveState: true, preserveScroll: true },
    );
  return (
    <RecordsScreen {...props}>
      <div className="flex flex-wrap items-center gap-3">
        <Tabs
          value={String(props.filters.type ?? "")}
          onValueChange={(value) => visit({ type: String(value) })}
        >
          <TabsList aria-label="Placement type">
            <TabsTrigger value="">All</TabsTrigger>
            <TabsTrigger value="feature">Features</TabsTrigger>
            <TabsTrigger value="network_post">Network posts</TabsTrigger>
            <TabsTrigger value="boost">Boosts</TabsTrigger>
          </TabsList>
        </Tabs>
        <DatePicker
          label="Placement month"
          value={props.filters.month ? `${props.filters.month}-01` : ""}
          onChange={(date) => visit({ month: date.slice(0, 7) })}
        />
      </div>
      <Tabs
        value={view}
        onValueChange={(value) => visit({ view: String(value) })}
      >
        <TabsList aria-label="Placement view">
          <TabsTrigger value="list">List</TabsTrigger>
          <TabsTrigger value="calendar">Calendar</TabsTrigger>
        </TabsList>
      </Tabs>
      {view === "calendar" && (
        <Card className="flex flex-wrap items-start gap-5 p-3">
          <Calendar
            mode="single"
            selected={day ? parseISO(day) : undefined}
            onSelect={(date) =>
              visit({ day: date ? format(date, "yyyy-MM-dd") : "" })
            }
          />
          <div className="space-y-3 p-3">
            <h2 className="text-sm font-semibold">Scheduled obligations</h2>
            <p className="max-w-sm text-description">
              Select a day to show obligations scheduled across that UTC date.
              Each obligation retains its own delivery evidence.
            </p>
            {day && (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => visit({ day: "" })}
              >
                Clear date
              </Button>
            )}
          </div>
        </Card>
      )}
    </RecordsScreen>
  );
}
