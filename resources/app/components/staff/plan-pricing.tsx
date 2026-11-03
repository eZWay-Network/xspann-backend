import { Link } from "@inertiajs/react";
import { useApp } from "@/contexts/app";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardFooter,
} from "@/components/ui/card";

export type Pricing = {
  environment: string;
  live_sales_enabled: boolean;
  plans: {
    name: string;
    monthly_cents: number;
    yearly_cents: number;
    monthly_checkout: boolean;
    price_configured: boolean;
  }[];
};

const usd = new Intl.NumberFormat("en-US", {
  style: "currency",
  currency: "USD",
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

export function PlanPricing({ pricing }: { pricing: Pricing }) {
  const { auth } = useApp();
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>Artist plan pricing</CardTitle>
          <CardDescription>
            Fans join for free ($0). Artist subscription prices are in USD.
            Yearly amounts cover a full year.
          </CardDescription>
        </div>
      </CardHeader>
      <table className="w-full table-fixed text-left text-sm">
        <caption className="sr-only">
          Artist plans, monthly and yearly prices, and Stripe checkout support
        </caption>
        <thead>
          <tr className="border-y bg-muted/50 text-xs text-muted-foreground">
            {["Plan", "Monthly", "Yearly"].map((label) => (
              <th
                key={label}
                scope="col"
                className="px-3 py-2.5 font-medium first:pl-4 sm:px-6 sm:first:pl-6"
              >
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {pricing.plans.map((plan) => (
            <tr key={plan.name} className="border-b last:border-0">
              <th
                scope="row"
                className="px-4 py-4 align-top font-medium sm:px-6"
              >
                {plan.name}
              </th>
              <td className="px-3 py-4 align-top sm:px-6">
                <span className="font-semibold tabular-nums">
                  {usd.format(plan.monthly_cents / 100)}
                </span>
                <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                  {plan.monthly_checkout
                    ? plan.price_configured
                      ? "Monthly price configured"
                      : "Monthly price ID missing"
                    : "Planned"}
                </span>
              </td>
              <td className="px-3 py-4 align-top sm:px-6">
                <span className="font-semibold tabular-nums">
                  {usd.format(plan.yearly_cents / 100)}
                </span>
                <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                  Planned
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <CardFooter>
        <p className="text-xs leading-5 text-muted-foreground">
          <strong className="font-medium text-foreground">
            Checkout availability.
          </strong>{" "}
          Stripe Checkout supports monthly Starter and Pro subscriptions. Yearly
          subscriptions and both Spotlight prices are planned. This deployment
          uses {pricing.environment} mode.{" "}
          {pricing.live_sales_enabled
            ? "Live sales are enabled."
            : "Live sales are disabled."}{" "}
          {auth?.permissions.includes("settings.view") && (
            <Link
              className="focus-ring rounded-sm text-primary underline"
              href="/settings/integrations"
            >
              Stripe setup guide
            </Link>
          )}
        </p>
      </CardFooter>
    </Card>
  );
}
