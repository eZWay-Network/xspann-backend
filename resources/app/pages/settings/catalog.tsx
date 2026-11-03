import {
  Configuration,
  type ConfigurationProps,
} from "@/components/staff/configuration";
import { PlanPricing, type Pricing } from "@/components/staff/plan-pricing";

export default function Catalog({
  sections,
  pricing,
}: ConfigurationProps & { pricing: Pricing }) {
  return (
    <Configuration sections={sections}>
      <PlanPricing pricing={pricing} />
    </Configuration>
  );
}
