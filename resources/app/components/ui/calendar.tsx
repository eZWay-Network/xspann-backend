import { calendarDate, todayIn } from "@/lib/staff-dates";
import { useApp } from "@/contexts/app";
import { DayPicker, type DayPickerProps } from "react-day-picker";
import { ChevronLeft, ChevronRight, ChevronDown } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";

export function Calendar(props: DayPickerProps) {
  const { clock } = useApp();
  return (
    <DayPicker
      timeZone="UTC"
      today={calendarDate(todayIn(clock))}
      noonSafe
      showOutsideDays
      className="p-3"
      classNames={{
        months: "relative flex flex-col gap-4",
        month: "space-y-3",
        month_caption: "flex h-9 items-center justify-center px-10",
        caption_label: "text-sm font-semibold",
        nav: "absolute inset-x-0 top-0 flex justify-between",
        button_previous: buttonVariants({ variant: "ghost", size: "icon" }),
        button_next: buttonVariants({ variant: "ghost", size: "icon" }),
        month_grid: "w-full border-collapse",
        weekdays: "flex",
        weekday:
          "w-9 pb-2 text-center text-[11px] font-medium text-muted-foreground",
        week: "mt-1 flex",
        day: "relative size-9 text-center text-sm",
        day_button:
          "focus-ring size-9 rounded-md hover:bg-muted transition-colors",
        selected:
          "rounded-md bg-primary text-primary-foreground [&_button]:hover:bg-primary",
        today: "font-semibold underline decoration-primary underline-offset-4",
        outside: "text-muted-foreground/50",
        disabled: "opacity-35",
        hidden: "invisible",
      }}
      components={{
        Chevron: ({ orientation }) =>
          orientation === "left" ? (
            <ChevronLeft className="size-4" />
          ) : orientation === "right" ? (
            <ChevronRight className="size-4" />
          ) : (
            <ChevronDown className="size-4" />
          ),
      }}
      {...props}
    />
  );
}
