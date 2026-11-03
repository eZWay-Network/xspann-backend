import { TZDate } from "react-day-picker";

export type StaffClock = {
  origin: number;
  span: number;
  index: number[];
  blocks: [number, number][][];
};

// Transitions describe the configured PHP timezone, independent of the device's timezone database.
function offsetAt(ms: number, clock: StaffClock) {
  const seconds = ms / 1000 - clock.origin;
  const block = Math.max(
    0,
    Math.min(clock.index.length - 1, Math.floor(seconds / clock.span)),
  );
  const changes = clock.blocks[clock.index[block]!]!;
  const within = seconds - block * clock.span;
  let lo = 0;
  let hi = changes.length;
  while (lo + 1 < hi) {
    const mid = Math.floor((lo + hi) / 2);
    if (changes[mid]![0] <= within) lo = mid;
    else hi = mid;
  }
  return changes[lo]![1];
}

// A UTC carrier represents calendar fields only. It is never submitted as a scheduled instant.
export function calendarDate(day: string) {
  return new TZDate(day + "T12:00:00Z", "UTC");
}

export function wallClock(ms: number, clock: StaffClock) {
  return new Date(ms + offsetAt(ms, clock) * 1000);
}

export function todayIn(clock: StaffClock) {
  return wallClock(Date.now(), clock).toISOString().slice(0, 10);
}

function offsetsAround(ms: number, clock: StaffClock) {
  return [
    ...new Set(
      [-86400000, 0, 86400000].map((delta) => offsetAt(ms + delta, clock)),
    ),
  ];
}

export function offsetLabel(ms: number, clock: StaffClock) {
  const offset = offsetAt(ms, clock);
  const seconds = Math.abs(offset);
  const part = (n: number) => String(n).padStart(2, "0");
  return (
    (offset < 0 ? "-" : "+") +
    part(Math.floor(seconds / 3600)) +
    ":" +
    part(Math.floor(seconds / 60) % 60) +
    (seconds % 60 ? ":" + part(seconds % 60) : "")
  );
}

export function repeatedTimeOffset(instant: Date, clock: StaffClock) {
  const ms = instant.getTime();
  const actual = offsetAt(ms, clock);
  const repeated = offsetsAround(ms, clock).some(
    (offset) =>
      offset !== actual &&
      offsetAt(ms + (actual - offset) * 1000, clock) === offset,
  );
  return repeated ? " (" + offsetLabel(ms, clock) + ")" : "";
}

/** A wall minute can name zero, one or two instants when the clock changes. */
export function possibleInstants(day: string, time: string, clock: StaffClock) {
  if (!day || !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) return [];
  const nominal = Date.parse(day + "T" + time + ":00Z");
  return offsetsAround(nominal, clock)
    .map((offset) => nominal - offset * 1000)
    .filter((ms) => wallClock(ms, clock).getTime() === nominal)
    .sort((a, b) => a - b)
    .map((ms) => new Date(ms).toISOString().replace(".000Z", "Z"));
}
