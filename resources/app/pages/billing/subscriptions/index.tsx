import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { PlanPricing, type Pricing } from "@/components/staff/plan-pricing";

export default function Subscriptions({
  pricing,
  ...records
}: RecordsProps & { pricing: Pricing }) {
  return (
    <RecordsScreen {...records}>
      <PlanPricing pricing={pricing} />
    </RecordsScreen>
  );
}
