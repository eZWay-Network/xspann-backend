import { ChevronDown, ExternalLink } from "lucide-react";
import type { ReactNode } from "react";
import { Link } from "@inertiajs/react";
import {
  Configuration,
  type ConfigurationProps,
} from "@/components/staff/configuration";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

type StripeGuide = {
  endpoint: string;
  environment: string;
  api_version: string;
  events: Record<string, string[]>;
  signing_secret_configured: boolean;
  portal_configured: boolean;
  automatic_tax: boolean;
};

function Step({
  title,
  open,
  children,
}: {
  title: string;
  open?: boolean;
  children: ReactNode;
}) {
  return (
    <details open={open} className="group border-t px-5 sm:px-6">
      <summary className="focus-ring flex cursor-pointer list-none items-center justify-between gap-3 rounded-sm py-4 font-medium [&::-webkit-details-marker]:hidden">
        {title}
        <ChevronDown
          aria-hidden="true"
          className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
        />
      </summary>
      <div className="space-y-4 pb-5 text-sm leading-6 text-muted-foreground [&_code]:break-all [&_code]:text-xs [&_code]:text-foreground">
        {children}
      </div>
    </details>
  );
}

function StripeDocs({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer"
      className="focus-ring inline-flex items-center gap-1 rounded-sm text-xs font-medium text-primary underline-offset-4 hover:underline"
    >
      {children}
      <ExternalLink aria-hidden="true" className="size-3" />
    </a>
  );
}

export default function Integrations({
  sections,
  stripe,
}: ConfigurationProps & { stripe: StripeGuide }) {
  return (
    <Configuration sections={sections}>
      <Card>
        <CardHeader>
          <div>
            <CardTitle>Stripe integration guide</CardTitle>
            <CardDescription>
              Connect artist subscriptions to Stripe Checkout, billing events
              and the customer portal.
            </CardDescription>
          </div>
          <Badge tone="info">
            {stripe.environment === "live" ? "Live mode" : "Test mode"}
          </Badge>
        </CardHeader>
        <div className="space-y-2 px-5 pb-5 sm:px-6">
          <h3 className="text-xs font-medium text-muted-foreground">
            Webhook endpoint
          </h3>
          <code className="block select-all break-all rounded-md bg-muted/60 px-3 py-2 text-xs">
            {stripe.endpoint}
          </code>
        </div>
        <Step title="Webhook setup" open>
          <p>
            In the Stripe Dashboard, open Workbench → Webhooks → Create an event
            destination. Use the same Stripe account and environment as the
            server.
          </p>
          <dl className="grid gap-x-4 gap-y-2 rounded-lg bg-muted/60 p-4 text-xs leading-5 sm:grid-cols-[9rem_1fr]">
            <dt>Event source</dt>
            <dd className="font-medium text-foreground">Your account</dd>
            <dt>Payload style</dt>
            <dd className="font-medium text-foreground">Snapshot</dd>
            <dt>API version</dt>
            <dd className="font-mono text-foreground">
              {stripe.api_version} (required)
            </dd>
            <dt>Destination type</dt>
            <dd className="font-medium text-foreground">Webhook endpoint</dd>
          </dl>
          <div>
            <h4 className="font-medium text-foreground">Select these events</h4>
            <p>
              These events reconcile checkout, paid access, refunds and
              disputes.
            </p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {Object.entries(stripe.events).map(([group, events]) => (
              <div key={group}>
                <h5 className="mb-1 text-xs font-medium">{group}</h5>
                <ul className="space-y-1">
                  {events.map((event) => (
                    <li key={event} className="leading-4">
                      <code>{event}</code>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
          <p>
            Set <code>APP_URL</code> to your public HTTPS backend origin and
            register the endpoint shown above. For local testing, register the
            same path on a public HTTPS tunnel that forwards to your development
            server.
          </p>
          <p>
            Reveal the endpoint signing secret (<code>whsec_…</code>) in Stripe
            and store it in <code>STRIPE_WEBHOOK_SECRETS</code> on the server.
            During rotation, separate the active secrets with commas. The
            signing secret is{" "}
            {stripe.signing_secret_configured ? "configured" : "missing"}.
          </p>
          <StripeDocs href="https://docs.stripe.com/webhooks">
            Stripe webhook setup
          </StripeDocs>
        </Step>
        <Step title="Account, prices and return URLs">
          <p>
            In the backend environment, start with{" "}
            <code>BILLING_ENVIRONMENT=test</code> and{" "}
            <code>APP_DEBUG=false</code>. Set <code>STRIPE_SECRET_KEY</code> to
            the server key for that environment and{" "}
            <code>STRIPE_ACCOUNT_ID</code> to the matching <code>acct_…</code>{" "}
            account ID.
          </p>
          <p>
            In Stripe’s product catalog, create a flat-rate recurring USD price
            for each supported offer. Use a licensed price, quantity one. Set{" "}
            <code>STRIPE_STARTER_PRICE_ID</code> and{" "}
            <code>STRIPE_PRO_PRICE_ID</code> to the monthly <code>price_…</code>{" "}
            IDs. Compare the amounts in the{" "}
            <Link href="/settings/catalog" className="text-primary underline">
              plan catalog
            </Link>
            .
          </p>
          <p>
            Set <code>BILLING_RETURN_URL</code> to the customer checkout success
            page, <code>BILLING_CANCEL_URL</code> to the canceled checkout page
            and <code>BILLING_PORTAL_RETURN_URL</code> to the customer billing
            page. Use fixed HTTPS URLs in live mode.
          </p>
          <StripeDocs href="https://docs.stripe.com/products-prices/manage-prices">
            Create products and prices
          </StripeDocs>
        </Step>
        <Step title="Customer portal">
          <p>
            In Stripe’s customer portal settings, save an active configuration
            in the same environment. Disable subscription plan and quantity
            changes, pauses and retention offers. If you enable cancellation,
            choose end of billing period.
          </p>
          <p>
            Find the saved configuration with{" "}
            <code>GET /v1/billing_portal/configurations</code> in the same
            Stripe environment. Copy its <code>bpc_…</code> ID into{" "}
            <code>STRIPE_PORTAL_CONFIGURATION</code> and apply the server
            configuration. XPN checks these settings when opening a portal
            session. The configuration ID is{" "}
            {stripe.portal_configured ? "set" : "missing"}.
          </p>
          <StripeDocs href="https://docs.stripe.com/api/customer_portal/configurations/create">
            Stripe portal configurations
          </StripeDocs>
        </Step>
        <Step title="Automatic tax">
          <p>
            <code>STRIPE_AUTOMATIC_TAX</code> is currently{" "}
            <strong>{stripe.automatic_tax ? "true" : "false"}</strong>. To use
            Stripe Tax, configure the business origin, applicable tax
            registrations, product tax codes and price tax behavior in Stripe,
            then set the server flag to <code>true</code>.
          </p>
          <p>
            With the flag enabled, new Checkout sessions calculate tax using the
            customer’s address. Use <code>false</code> to leave automatic
            calculation off for new checkouts.
          </p>
          <StripeDocs href="https://docs.stripe.com/tax/checkout/page">
            Stripe Tax with Checkout
          </StripeDocs>
        </Step>
        <Step title="Verify a test subscription">
          <p>
            After applying the server settings, restart the billing worker and
            keep it running with{" "}
            <code>php spark queue:work --queue=billing --sleep=1</code>.
            Complete a test checkout through XPN. In Workbench, check for a
            successful delivery, then inspect Billing → Webhooks and Invoices in
            XPN. A verified paid invoice grants access for its service period.
          </p>
          <p>
            Open the customer portal, check its permitted actions and return to
            the customer billing page. If automatic tax is enabled, check the
            address and tax amount on the test invoice.
          </p>
        </Step>
      </Card>
    </Configuration>
  );
}
