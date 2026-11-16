/**
 * @bmaglass/sdk — typed client for the BMaGlass Pay Developer API.
 *
 * Auth: an API key created in the BMaGlass Pay app. Keys carry their
 * environment with them: bmg_live_… (legacy bmp_live_…) = production,
 * real money; bmg_sandbox_… (legacy bmp_test_…) = sandbox — mainnet
 * formats, simulated money: sandbox invoices/payment links settle only
 * through the simulate-payment endpoints and never touch a real
 * wallet. The environment is read from the key alone (client.environment);
 * no option can override it.
 */
import { createHmac, randomUUID, timingSafeEqual } from "node:crypto";

export class BMaGlassError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = "BMaGlassError";
  }
}

export interface Wallet { id: string; currency: string; balance: number; status: string; created_at: string; updated_at: string }
export interface Transaction { uuid_id: string; amount: number; currency: string; direction: string; status: string; description: string | null; recipient_name: string | null; fee_amount: number | null; created_at: string }
export interface PaymentLink { id: string; title: string; description?: string | null; amount: number | null; currency: string; link_code: string; status: string; payment_count?: number; total_collected?: number; expires_at?: string | null; created_at: string; /** Who funds the platform fee on this link ("seller" | "buyer") — the merchant default from GET /settings unless overridden at creation. */ fee_payer?: "seller" | "buyer"; /** Buyer return URL offered by the hosted pay page after payment, when set. */ redirect_url?: string | null; /** Platform fee on the link amount at the current platform rate. */ fee_amount?: number | null; /** What the buyer pays in total (amount + fee when fee_payer is "buyer"). */ buyer_total?: number | null; /** What the seller receives after the fee (amount − fee when fee_payer is "seller"). */ seller_receives?: number | null }
export interface WebhookEndpoint { id: string; url: string; events: string[]; is_active: boolean; created_at: string; secret?: string }
export interface WebhookDelivery { id: string; webhook_id: string | null; event_id: string; event_type: string; webhook_url: string; status: string; attempts: number; response_status: number | null; error_message: string | null; created_at: string }
export interface BitcoinQuote { id: string; side: "buy" | "sell"; amount_zmw: number; amount_sats: number; fee_bps: number; fee_zmw: number; mid_rate_zmw: number; expires_at: string; created_at: string }
export interface AccountSettings { fee_payer_default: "seller" | "buyer"; platform_fee_percent: number | null; paypal_platform_fee_percent: number | null; /** The merchant's mobile-money fee-bearer <redacted> (who funds the 1% momo service charge) — returned by GET /settings live (gate14-api-webhook-a1). */ momo_fee_bearer: "seller" | "buyer"; note: string }
export interface FeatureState { status: "enabled" | "disabled" | "not_entitled" | "pending_verification"; reason_code: string | null; /** Whether the business has a gateway connection saved for this feature (mobile_money). */ connected?: boolean; /** The merchant's fee-bearer default for this feature (mobile_money), from GET /settings. */ fee_bearer?: "seller" | "buyer" }
/** mobile_money carries two extra read-only fields beyond FeatureState: whether a connection exists for this key's environment, and who pays the 1% service charge on a collection ("seller" | "buyer") — the merchant's own choice, so a plugin can show the buyer the true total (product + service charge when the buyer pays) before it starts a collection. */
export interface MobileMoneyFeatureState extends FeatureState { connected: boolean; fee_bearer: "seller" | "buyer" }
export interface ConnectorConfig {
  environment: "sandbox" | "production"; livemode: boolean; config_version: string;
  key: { key_id: string; scopes: string[]; status: string };
  business: { display_name: string | null; country: string | null; currency: string | null; status: string };
  features: { invoices: FeatureState; payment_links: FeatureState; bitcoin: FeatureState; transfers: FeatureState; mobile_money: MobileMoneyFeatureState };
  settlement: { type: string; currency: string | null };
}
export interface Invoice { id: string; amount: number; currency: string; description: string | null; customer_email: string | null; status: "open" | "paid" | "expired" | "cancelled"; environment: "sandbox" | "production"; livemode: boolean; metadata: Record<string, unknown>; paid_at: string | null; created_at: string }
export interface PaymentLinkAttempt { id: string; fiat_amount: number | null; fiat_currency: string; fee_payer: string; fee_percent: number | null; fee_sats: number | null; amount_sats: number | null; required_sats: number | null; seen_sats: number | null; received_sats: number | null; status: string; onchain_address: string | null; lightning_invoice: string | null; expires_at: string | null; settled_at: string | null; created_at: string }
export interface MobileMoneyCollection { reference: string; merchant_reference: string | null; collection_id: string; transaction_id: string | null; amount: number; /** The product amount the 1% service charge is computed on (== amount in seller mode). */ base_amount: number; /** The 1% service charge on base_amount. */ service_charge: number; /** Who funds the service charge, derived from the settled row. */ fee_bearer: "seller" | "buyer"; currency: string; operator: "mtn" | "airtel" | "zamtel"; phone: string; status: "pending" | "successful" | "failed"; failure_reason: string | null; environment: "sandbox" | "production"; livemode: boolean; wallet_balance: number | null; settled_at: string | null; created_at: string }

export interface BMaGlassOptions {
  apiKey: string;
  /** Defaults to the production developer-api function URL. */
  baseUrl?: string;
  fetchImpl?: typeof fetch;
  /** Per-request timeout in milliseconds (default 30000). A request that
   *  does not settle in time fails as a BMaGlassError with code
   *  "network_error" instead of hanging forever. */
  timeoutMs?: number;
  /** Retries after a transient failure (default 2 — 3 attempts total).
   *  Only idempotent requests are ever retried: every GET/DELETE, and
   *  POSTs that carry an Idempotency-Key (payment-link / invoice /
   *  transfer creates — the SDK sends one per logical call, see
   *  `newIdempotencyKey` — and mobile-money collections, keyed by
   *  reference / idempotency_key). A POST with no idempotency key
   *  (bitcoin/quote, simulate-payment, webhook create/test) is never
   *  retried: the server could not tell a retry from a second call.
   *  Retried failures are HTTP 502/503/504 and transport failures
   *  (including timeouts). Backoff is exponential with full jitter:
   *  before retry N the client sleeps a uniform random delay in
   *  [0, min(4000, retryBaseDelayMs * 2^(N-1))] ms. Set to 0 to
   *  disable retries entirely. */
  maxRetries?: number;
  /** Base backoff in milliseconds (default 500). See maxRetries. */
  retryBaseDelayMs?: number;
  /** Sleep implementation (tests). Defaults to setTimeout. */
  sleepImpl?: (ms: number) => Promise<void>;
}

/** One fresh idempotency key per logical create. The same key is
 *  reused across that call's retries, so the server replays the
 *  original object instead of creating a second one. */
export function newIdempotencyKey(): string {
  return randomUUID();
}

export const SITE_BASE = "https://bmaglasspay.online";
export const SUPPORTED_CURRENCIES = ["ZMW", "USD"] as const;

/** Hosted payment page URL for a link code. */
export function paymentLinkUrl(linkCode: string): string {
  return `${SITE_BASE}/pay/${encodeURIComponent(linkCode)}`;
}

/**
 * Guard: only currencies with wallets may move money (transfers,
 * payment links). Anything else is refused — never converted silently.
 * Invoices are NOT guarded: the API accepts any 3-letter invoice
 * currency (a payment request, not a wallet move).
 */
export function assertSupportedCurrency(currency: string): string {
  const c = String(currency || "").toUpperCase();
  if (!(SUPPORTED_CURRENCIES as readonly string[]).includes(c)) {
    throw new BMaGlassError(0, "unsupported_currency",
      `BMaGlass Pay wallets are ZMW and USD. This is ${c || "?"}, which has no BMaGlass wallet — no exchange rate has been applied. Use ZMW or USD with BMaGlass Pay.`);
  }
  return c;
}

const DEFAULT_BASE = "https://hhxwwozpvgktuqutfkhc.supabase.co/functions/v1/developer-api";

export class BMaGlass {
  /** Legacy alias of `environment` ("live" ≡ production, "test" ≡ sandbox). */
  readonly mode: "test" | "live";
  /** The environment, derived ONLY from the key — never from options. */
  readonly environment: "sandbox" | "production";
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly fetchImpl: typeof fetch;
  private readonly timeoutMs: number;
  private readonly maxRetries: number;
  private readonly retryBaseDelayMs: number;
  private readonly sleepImpl: (ms: number) => Promise<void>;

  constructor(options: BMaGlassOptions) {
    if (!/^(bmg|bmp)_/.test(options.apiKey ?? "")) {
      throw new BMaGlassError(0, "invalid_api_key", "apiKey must be a BMaGlass Pay API key (bmg_live_…, bmg_sandbox_…, or a legacy bmp_ key).");
    }
    this.apiKey = options.apiKey;
    this.mode = options.apiKey.includes("_live_") ? "live" : "test";
    this.environment = this.mode === "live" ? "production" : "sandbox";
    this.baseUrl = (options.baseUrl ?? DEFAULT_BASE).replace(/\/$/, "");
    this.fetchImpl = options.fetchImpl ?? fetch;
    this.timeoutMs = options.timeoutMs ?? 30000;
    this.maxRetries = options.maxRetries ?? 2;
    this.retryBaseDelayMs = options.retryBaseDelayMs ?? 500;
    this.sleepImpl = options.sleepImpl ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  }

  /** Backoff before retry N (1-based): uniform in [0, cap] ms, cap
   *  doubling from retryBaseDelayMs up to 4000 ms. */
  private retryDelayMs(attempt: number): number {
    return Math.random() * Math.min(4000, this.retryBaseDelayMs * 2 ** (attempt - 1));
  }

  private async request<T>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}): Promise<T> {
    // Retryable iff the request is provably safe to repeat: a read, a
    // delete, or a write carrying an Idempotency-Key (the server
    // replays the original object for a repeated key). Anything else
    // gets exactly one attempt — a blind retry could double-settle.
    const retryable = method === "GET" || method === "DELETE" || headers["Idempotency-Key"] !== undefined;
    const maxAttempts = retryable ? Math.max(1, this.maxRetries + 1) : 1;
    let attempt = 0;
    for (;;) {
      attempt++;
      try {
        return await this.requestOnce<T>(method, path, body, headers);
      } catch (e: any) {
        const transient = e instanceof BMaGlassError &&
          (e.code === "network_error" || e.status === 502 || e.status === 503 || e.status === 504);
        if (!transient || attempt >= maxAttempts) throw e;
        await this.sleepImpl(this.retryDelayMs(attempt));
      }
    }
  }

  private async requestOnce<T>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}): Promise<T> {
    let res: Response;
    try {
      // Timeout is enforced TWO ways: the signal aborts a real fetch
      // (freeing the socket), and the race guarantees the call settles
      // even for a custom fetchImpl that ignores `signal`.
      let timer: ReturnType<typeof setTimeout> | undefined;
      const timeout = new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(Object.assign(new Error("timeout"), { name: "TimeoutError" })), this.timeoutMs);
      });
      try {
        res = await Promise.race([
          this.fetchImpl(`${this.baseUrl}/${path}`, {
            method,
            headers: { "X-Api-Key": this.apiKey, "Content-Type": "application/json", ...headers },
            body: body === undefined ? undefined : JSON.stringify(body),
            // AbortSignal.timeout is supported by Node 18+ and modern
            // browsers; a custom fetchImpl that ignores `signal` simply
            // never aborts, and the race above still settles the call.
            signal: AbortSignal.timeout(this.timeoutMs),
          }),
          timeout,
        ]);
      } finally {
        if (timer !== undefined) clearTimeout(timer);
      }
    } catch (e: any) {
      // Transport failures (DNS, connection refused, timeout) used to
      // leak out as raw TypeErrors/DOMExceptions. Every failure from
      // this client is a typed BMaGlassError.
      const timedOut = e?.name === "TimeoutError" || e?.name === "AbortError";
      throw new BMaGlassError(0, "network_error",
        timedOut
          ? `BMaGlass Pay did not answer within ${this.timeoutMs}ms. Check the connection and try again.`
          : `Couldn't reach BMaGlass Pay. Check the connection and try again. (${e?.message ?? e})`);
    }
    const text = await res.text();
    let parsed: any = {};
    try { parsed = text ? JSON.parse(text) : {}; } catch { /* non-JSON */ }
    if (!res.ok) {
      throw new BMaGlassError(res.status, parsed?.error ?? "request_failed", parsed?.message ?? `Request failed with HTTP ${res.status}`);
    }
    return (parsed?.data ?? parsed) as T;
  }

  /**
   * Everything a connector renders from: this key's environment, the
   * business's country/currency/status, feature states (mobile_money
   * is enabled only for businesses registered in Zambia with the
   * feature switched on; not_entitled otherwise) and the settlement
   * type. Authenticated by the key alone — there is no business
   * parameter. The response carries a config_version (ETag).
   */
  connectorConfig = () => this.request<ConnectorConfig>("GET", "connector/config");
  invoices = {
    list: () => this.request<Invoice[]>("GET", "invoices"),
    get: (id: string) => this.request<Invoice>("GET", `invoices/${encodeURIComponent(id)}`),
    create: (input: { amount: number; currency?: string; description?: string; customer_email?: string; metadata?: Record<string, unknown> }, idempotencyKey?: string) =>
      this.request<Invoice>("POST", "invoices", input, { "Idempotency-Key": idempotencyKey ?? newIdempotencyKey() }),
    /** Sandbox only: drive a sandbox invoice to paid (fires invoice.paid, flagged simulated). Production keys get 403 simulation_not_available. */
    simulatePayment: (id: string) => this.request<Invoice & { simulated?: boolean }>("POST", `invoices/${encodeURIComponent(id)}/simulate-payment`),
  };
  wallets = {
    list: () => this.request<Wallet[]>("GET", "wallets"),
  };
  transactions = {
    list: (limit = 25) => this.request<Transaction[]>("GET", `transactions?limit=${Math.max(1, Math.min(100, limit))}`),
  };
  transfers = {
    /** Production keys only. Sandbox keys receive a 403 test_mode_read_only BMaGlassError. Retried safely: one Idempotency-Key per logical call (caller-supplied, or auto-generated) is reused across retries, and the server replays the original transfer. */
    create: (input: { to_user_id: string; amount: number; currency?: string; description?: string; recipient_name?: string }, idempotencyKey?: string) =>
      this.request<{ transaction_id: string; new_sender_balance: number }>("POST", "transfers",
        { ...input, currency: assertSupportedCurrency(input.currency ?? "ZMW") },
        { "Idempotency-Key": idempotencyKey ?? newIdempotencyKey() }),
  };
  paymentLinks = {
    list: () => this.request<PaymentLink[]>("GET", "payment-links"),
    /** One link by UUID id or link_code. The list caps at 100 (newest first); this read is how a shop reconciler verifies an older link. */
    get: (id: string) => this.request<PaymentLink>("GET", `payment-links/${encodeURIComponent(id)}`),
    /** Creates a link in the key's environment; a sandbox key creates a sandbox link (the hosted checkout refuses those — settlement is simulation-only). `fee_payer` overrides the merchant default from GET /settings for this link only ("seller" — the fee comes out of what the seller receives; "buyer" — the fee is added on top and the seller receives the full price). `redirect_url` is the buyer return URL the hosted pay page offers after payment. Retried safely: one Idempotency-Key per logical call (caller-supplied, or auto-generated) is reused across retries, and the server replays the original link instead of creating a second one. */
    create: (input: { title: string; description?: string; amount?: number; currency?: string; expires_at?: string; fee_payer?: "seller" | "buyer"; redirect_url?: string }, idempotencyKey?: string) => {
      if (input.fee_payer !== undefined && input.fee_payer !== "seller" && input.fee_payer !== "buyer") {
        throw new BMaGlassError(0, "invalid_fee_payer", "fee_payer must be 'seller' or 'buyer'.");
      }
      return this.request<PaymentLink>("POST", "payment-links",
        { ...input, currency: assertSupportedCurrency(input.currency ?? "ZMW") },
        { "Idempotency-Key": idempotencyKey ?? newIdempotencyKey() });
    },
    /** A link's Bitcoin payment attempts (quote, sats received, status). */
    attempts: (id: string) => this.request<PaymentLinkAttempt[]>("GET", `payment-links/${encodeURIComponent(id)}/attempts`),
    /** Sandbox only: mark one simulated payment on a sandbox link (fires payment_link.paid, flagged simulated). */
    simulatePayment: (id: string) => this.request<PaymentLink & { simulated?: boolean }>("POST", `payment-links/${encodeURIComponent(id)}/simulate-payment`),
  };
  mobileMoney = {
    /**
     * Start a mobile money collection on the business's connected
     * mobile money account (Zambia, ZMW): the customer approves the
     * prompt on their phone (MTN, Airtel or Zamtel). The key's
     * environment selects which connection is used — a sandbox key
     * runs against the sandbox connection (gateway test numbers; no
     * real phone is ever charged), a production key against the
     * production connection. `reference` is your own reference for
     * the collection and doubles as the idempotency key: repeating a
     * create with the same reference returns the original collection
     * instead of charging the customer twice. Requires the
     * mobile_money:create scope. Gate errors: 400
     * business_not_set_up / not_configured, 403 not_entitled (the
     * business is not registered in Zambia, or mobile money is
     * switched off) / business_inactive.
     */
    createCollection: (input: { amount: number; phone: string; operator?: "mtn" | "airtel" | "zamtel"; reference?: string; idempotency_key?: string }) => {
      // Retried safely: the effective key is the caller's
      // idempotency_key, else their reference (which doubles as the
      // key server-side), else a fresh UUID sent as idempotency_key.
      // The same key rides every retry of this logical call.
      const key = input.idempotency_key ?? input.reference ?? newIdempotencyKey();
      const body: Record<string, unknown> = { ...input };
      if (input.idempotency_key === undefined && input.reference === undefined) body.idempotency_key = key;
      return this.request<MobileMoneyCollection>("POST", "mobile-money/collections", body, { "Idempotency-Key": key });
    },
    /**
     * A collection's current state, by platform reference (BMG-…) or
     * your own reference. Polling refreshes from the rail: when the
     * collection settles, the business's ZMW wallet is credited in
     * full and mobile_money.collection.completed fires to the
     * environment's webhook endpoints. Requires mobile_money:read.
     */
    getCollection: (reference: string) =>
      this.request<MobileMoneyCollection>("GET", `mobile-money/collections/${encodeURIComponent(reference)}`),
  };
  settings = {
    /** The merchant's fee-bearer default (fee_payer_default) + the momo fee-bearer (momo_fee_bearer) + the platform's current fee rates. Read-only; any valid key. */
    get: () => this.request<AccountSettings>("GET", "settings"),
  };
  bitcoin = {
    balance: () => this.request<Array<{ asset: string; balance_sats: number; available_sats: number; updated_at: string }>>("GET", "bitcoin/balance"),
    depositAddress: () => this.request<{ address: string; derivation_path: string; is_active: boolean; created_at: string }>("GET", "bitcoin/deposit-address"),
    /** Quote only — execution happens in the app. Works with sandbox keys. `side` must be "buy" or "sell": a typo here used to be silently treated as a buy quote, which flips the money direction. Not retried: each call mints a quote row (no idempotency key), so a retry after a 503 would mint a second quote. */
    quote: (input: { side: "buy" | "sell"; amount_zmw?: number; amount_sats?: number }) => {
      if (input.side !== "buy" && input.side !== "sell") {
        throw new BMaGlassError(0, "invalid_side", "side must be 'buy' or 'sell'.");
      }
      return this.request<BitcoinQuote>("POST", "bitcoin/quote", input);
    },
  };
  webhooks = {
    list: () => this.request<WebhookEndpoint[]>("GET", "webhooks"),
    /** The returned secret is shown once — store it immediately. */
    create: (input: { url: string; events?: string[] }) => this.request<WebhookEndpoint>("POST", "webhooks", input),
    remove: (id: string) => this.request<{ id: string; is_active: boolean }>("DELETE", `webhooks/${encodeURIComponent(id)}`),
    test: (id: string) => this.request<unknown>("POST", `webhooks/${encodeURIComponent(id)}/test`),
    deliveries: () => this.request<WebhookDelivery[]>("GET", "webhook-deliveries"),
  };
}

/**
 * Verify a webhook delivery. `rawBody` must be the exact request body
 * string; `signatureHeader` is the X-BMaGlass-Signature header value
 * ("sha256=<hex>"). Returns the parsed event when valid, else null.
 */
export function verifyWebhookSignature(rawBody: string, signatureHeader: string, secret: string): boolean {
  if (!signatureHeader?.startsWith("sha256=") || !secret) return false;
  const expected = "sha256=" + createHmac("sha256", secret).update(rawBody, "utf8").digest("hex");
  const a = Buffer.from(expected);
  const b = Buffer.from(signatureHeader);
  return a.length === b.length && timingSafeEqual(a, b);
}

export function parseWebhookEvent<T = unknown>(rawBody: string, signatureHeader: string, secret: string): { id: string; type: string; created: string; data: T } | null {
  if (!verifyWebhookSignature(rawBody, signatureHeader, secret)) return null;
  try { return JSON.parse(rawBody); } catch { return null; }
}

export default BMaGlass;
