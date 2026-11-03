import { useEffect, useRef, useState } from "react";
import { useApp } from "@/contexts/app";
import {
  possibleInstants,
  wallClock,
  offsetLabel,
  type StaffClock,
} from "@/lib/staff-dates";
import { DatePicker } from "./date-picker";
import { Input } from "./input";
import { Select } from "./select";

function draftFrom(value: string, zone: string, clock: StaffClock) {
  const local = value ? wallClock(Date.parse(value), clock).toISOString() : "";
  return {
    value,
    zone,
    day: local.slice(0, 10),
    time: local ? local.slice(11, 16) : "12:00",
    touchedTime: false,
  };
}

export function DateTime({
  label,
  value,
  onChange,
  required = true,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
}) {
  const { preferences, clock } = useApp();
  const zone = preferences.timezone;
  const [draft, setDraft] = useState(() => draftFrom(value, zone, clock));
  if (draft.value !== value || draft.zone !== zone)
    setDraft(draftFrom(value, zone, clock));

  const input = useRef<HTMLInputElement>(null);
  const choices = possibleInstants(draft.day, draft.time, clock);
  // Preserve seconds on an untouched schedule while matching its occurrence by minute.
  const occurrence =
    choices.find(
      (instant) =>
        Date.parse(value) >= Date.parse(instant) &&
        Date.parse(value) < Date.parse(instant) + 60000,
    ) ?? "";
  const needsDay = !draft.day && (required || draft.touchedTime);
  const gap = draft.day && draft.time && !choices.length;
  const error = needsDay
    ? "Choose a date."
    : gap
      ? "This time does not exist on this date. Choose another time."
      : choices.length === 2 && !occurrence
        ? "This time occurs twice. Choose which occurrence to use."
        : "";
  useEffect(() => {
    input.current?.setCustomValidity(error);
  }, [error]);

  function update(day: string, time: string, touchedTime: boolean) {
    const matches = possibleInstants(day, time, clock);
    const next = matches.length === 1 ? matches[0]! : "";
    setDraft({ day, time, zone, value: next, touchedTime });
    onChange(next);
  }
  return (
    <fieldset className="min-w-0 space-y-2">
      <legend className="text-sm font-medium">
        {label}
        {!required && " (optional)"}
      </legend>
      <div className="grid gap-2 sm:grid-cols-[1fr_8rem]">
        <DatePicker
          label={label}
          value={draft.day}
          required={required}
          onChange={(day) => update(day, day ? draft.time : "12:00", false)}
        />
        <Input
          ref={input}
          type="time"
          aria-label={label + " time"}
          required={required || !!draft.day}
          value={draft.time}
          onChange={(event) =>
            update(draft.day, event.target.value, !!event.target.value)
          }
        />
      </div>
      <p className="text-xs text-muted-foreground">{zone}</p>
      {gap ? (
        <p role="alert" className="text-xs text-destructive">
          {error}
        </p>
      ) : null}
      {choices.length === 2 && (
        <Select
          label={label + " occurrence"}
          value={occurrence}
          options={[
            { value: "", label: "Choose which occurrence" },
            ...choices.map((instant, index) => ({
              value: instant,
              label:
                (index === 0 ? "First" : "Second") +
                " · " +
                offsetLabel(Date.parse(instant), clock),
            })),
          ]}
          onValueChange={(instant) => {
            setDraft({ ...draft, value: instant });
            onChange(instant);
          }}
        />
      )}
    </fieldset>
  );
}
