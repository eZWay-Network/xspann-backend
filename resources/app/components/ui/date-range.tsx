import { useState } from "react";
import { Popover } from "@base-ui/react/popover";
import { CalendarDays } from "lucide-react";
import { format, parseISO } from "date-fns";
import type { DateRange as Range } from "react-day-picker";
import { Calendar } from "./calendar";
import { Button } from "./button";
export function DateRange({
  from,
  to,
  onChange,
  disabled = false,
}: {
  from: string;
  to: string;
  onChange: (from: string, to: string) => void;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [range, setRange] = useState<Range | undefined>({
    from: parseISO(from),
    to: parseISO(to),
  });
  return (
    <Popover.Root
      open={open}
      onOpenChange={(value) => {
        setOpen(value);
        if (value) setRange({ from: parseISO(from), to: parseISO(to) });
      }}
    >
      <Popover.Trigger
        render={<Button variant="secondary" disabled={disabled} />}
      >
        <CalendarDays />
        {format(parseISO(from), "MMM d")} –{" "}
        {format(parseISO(to), "MMM d, yyyy")}
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Positioner sideOffset={8} align="end" className="z-50">
          <Popover.Popup className="rounded-lg border bg-card shadow-popover outline-none">
            <Popover.Title className="px-4 pt-4 text-sm font-semibold">
              Reporting dates · UTC
            </Popover.Title>
            <Calendar
              mode="range"
              selected={range}
              onSelect={setRange}
              defaultMonth={range?.from}
              max={89}
              disabled={{
                after: parseISO(new Date().toISOString().slice(0, 10)),
              }}
            />
            <div className="flex items-center justify-between gap-4 border-t p-3">
              <span className="text-xs text-muted-foreground">
                Up to 90 days
              </span>
              <Button
                size="sm"
                disabled={!range?.from || !range?.to}
                onClick={() => {
                  if (range?.from && range.to) {
                    onChange(
                      format(range.from, "yyyy-MM-dd"),
                      format(range.to, "yyyy-MM-dd"),
                    );
                    setOpen(false);
                  }
                }}
              >
                Apply dates
              </Button>
            </div>
          </Popover.Popup>
        </Popover.Positioner>
      </Popover.Portal>
    </Popover.Root>
  );
}
