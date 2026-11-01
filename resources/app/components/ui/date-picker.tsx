import { useState } from "react";
import { Popover } from "@base-ui/react/popover";
import { CalendarDays } from "lucide-react";
import { format, parseISO, isValid } from "date-fns";
import { Calendar } from "@/components/ui/calendar";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function DatePicker({
  value,
  onChange,
  name,
  label,
  placeholder = "Choose a date",
  disabled = false,
  required = false,
}: {
  value: string;
  onChange: (value: string) => void;
  name?: string;
  label: string;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const date = value ? parseISO(value) : undefined;
  const selected = date && isValid(date) ? date : undefined;
  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      {name && <input type="hidden" name={name} value={value} />}
      <Popover.Trigger
        disabled={disabled}
        aria-label={`${label}: ${selected ? format(selected, "PPP") : placeholder}`}
        className={cn(
          "field-control flex items-center justify-between gap-3 text-left",
          !selected && "text-muted-foreground",
        )}
      >
        {selected ? format(selected, "MMM d, yyyy") : placeholder}
        <CalendarDays className="size-4 shrink-0 text-muted-foreground" />
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Positioner sideOffset={8} align="start" className="z-50">
          <Popover.Popup className="rounded-lg border bg-card shadow-popover outline-none">
            <Popover.Title className="sr-only">{label}</Popover.Title>
            <Calendar
              mode="single"
              required={required}
              selected={selected}
              defaultMonth={selected}
              onSelect={(day: Date | undefined) => {
                onChange(day ? format(day, "yyyy-MM-dd") : "");
                setOpen(false);
              }}
            />
            <div className="flex justify-between border-t p-2">
              <Button
                disabled={required}
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  onChange("");
                  setOpen(false);
                }}
              >
                Clear date
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  onChange(format(new Date(), "yyyy-MM-dd"));
                  setOpen(false);
                }}
              >
                Today
              </Button>
            </div>
          </Popover.Popup>
        </Popover.Positioner>
      </Popover.Portal>
    </Popover.Root>
  );
}
