export type Boost = {
  id: number;
  artist_id: number | null;
  video_id: number | null;
  status: string;
  version: number;
  surface: string;
  price_cents: number;
  duration_seconds: number;
  starts_at: string | null;
  ends_at: string | null;
  rights_version: string;
  rights_confirmed_at: string | null;
  staff_hold: boolean;
  stop_reason: string | null;
  settle_after: string | null;
  settled_at: string | null;
  report: {
    reserved_cents: number;
    charged_cents: number;
    returned_cents: number;
    delivered_seconds: number;
    undelivered_seconds: number;
    render_events: number;
    click_events: number;
  };
};
export type Placement = {
  id: number;
  artist_id: number | null;
  type: string;
  month: string | null;
  destination: string;
  status: string;
  version: number;
  starts_at: string | null;
  ends_at: string | null;
  due_at: string | null;
  reason: string | null;
  extension: {
    starts_at: string | null;
    ends_at: string | null;
    consent: string | null;
    consented_at: string | null;
  };
  evidence: {
    proof: string;
    actual_starts_at: string;
    actual_ends_at: string;
    note: string;
  } | null;
};
