# Sponsored membership renewal API

Status: implemented on `feat-renew-api` for local review and testing; not deployed. The design and acceptance criteria below remain the contract.

Implementation notes: the adapter accepts inspected Pro 8.1.1 only. It uses read interception around the original date helper for previews, without temporary writes. Existing-payment purpose can be an Add Manual Payment Note JSON object with `sponsor_name`, `start_plan` and `completed_cycles`, or the same structured object in `arm_extra_vars.bono_sponsored_renewal`. The SQL journal survives uninstall. Uncertain operations are conservatively blocked for manual reconciliation; no automatic rollback is claimed. Status reads remain available with mutation toggles disabled. API advisory locks do not coordinate portal/cron/third-party writes; avoid concurrent external edits during settlement.

Intended destination: `bono_arm_api/docs/sponsored-renewal-api-spec.md`.

## Objective

Provide one operation for a member whose subscription fees were paid externally by a sponsor. A completed operation must:

1. Reactivate the ARMember account.
2. Renew exactly one natural subscription cycle, preserving its schedule.
3. Create one successful manual transaction, or explicitly link the matching payment already recorded.

This records external money received. It does not charge a card, collect money from the sponsor, initiate a refund, or change Mailchimp tags.

## Plugin integration

Follow the existing `bono_arm_api` architecture and `AGENTS.md`:

- Add the route to `bono_armember/v2`; preserve all published v1/v2 routes and parameters.
- Use WordPress authentication, normally Application Passwords over HTTPS. Do not authenticate this route with an ARMember API key or credentials in a URL.
- Add `bono_arm_api_renew_members` and require it together with `bono_arm_api_activate_members`. Grant the new capability to administrators once, following `Capabilities::maybe_upgrade()`, and preserve operator removals on subsequent upgrades.
- Add `bono_arm_api_enable_member_renewals`, default false. Execution also requires the existing member-activation toggle. The existing transactions toggle controls reads; do not silently repurpose it for payment writes.
- Keep permission checks in the REST controller, ARMember calls in its Gateway/adapter layer, and SQL/operation persistence in Infrastructure.
- Define REST schemas, reject unknown fields, and use normal WordPress REST error envelopes.
- Keep this proposal separate from the published OpenAPI files until implementation is ready. Writing this specification requires no plugin version bump or release.

## Supported scope

The first implementation supports an existing paid recurring subscription using `manual_subscription`, with a valid stored schedule and another cycle available. Already-active members are supported; their account activation is a no-op.

Reject automatic-debit subscriptions rather than silently converting their payment mode or allowing a processor to charge the same cycle. Reject free/lifetime plans, missing plan assignments, exhausted finite recurring agreements, and missing/invalid schedules. Paid-finite plans require their own adapter and are outside the first implementation.

Restore nonpayment-related suspension for the target plan only. Native Pro 8.1.1 clears the grace action after automatic suspension; later successful payments can hide a previous failed log. When the cause is ambiguous, return `bono_arm_api_unsupported_subscription` with `data.reason: "suspension_reason_unverified"`. A trusted operator who explicitly confirms this target was suspended for nonpayment may send optional top-level `suspension_reason: "nonpayment"`. The target must have a valid overdue schedule. Never default confirmation across members or infer it solely from historical failures. The confirmation is part of the canonical command, preview binding, and durable journal and must be repeated unchanged on commit/replay. It does not bypass cancellation, account restrictions, unrelated grace actions or other-plan restrictions. Reject deliberately cancelled/deleted subscriptions or unrelated administrative restrictions instead of silently overriding them. Other subscriptions, roles, and restrictions must remain intact.

## Natural renewal cadence

The processing date and sponsor payment date must never replace the subscription start date.

For the supported recurring plan:

1. Read `arm_start_plan`, `arm_completed_recurring`, `arm_next_due_payment`, the selected payment cycle, and `arm_current_plan_detail`.
2. Use the member's stored plan snapshot and selected cycle, including its configured billing interval/day.
3. Increment the completed-cycle count by exactly one.
4. Recalculate the next due date through the algorithm used by `arm_get_next_due_date(user_id, plan_id, false, payment_cycle)` against that projected state.
5. Preserve `arm_start_plan`. Do not calculate `today + interval`, reset the cycle count, or repeatedly advance until the member becomes current.

ARMember calculates dates from the original start, completed-cycle count, interval, and billing-day setting. Do not replace that with a separate anniversary algorithm. Preserve ARMember's behavior for month ends, leap years, selected cycles, and the site's timezone.

Example: an annual subscription starting October 1, 2025, with one completed payment and an October 1, 2026 due date, advances to October 1, 2027 after one sponsored renewal. Processing on November 13, 2026 must not move the anniversary to November 13.

Preview must calculate projected state without temporarily persisting changes to live member metadata. Extract a pure calculator or use an isolated adapter of the original algorithm.

### More than one period overdue

The user requires an active membership after payment. If advancing one cycle still leaves entitlement expired, return `bono_arm_api_still_overdue` with the projected due date and make no business changes. Do not silently mark an expired entitlement active or settle additional periods. Earlier unpaid periods require an explicit separate decision.

The resulting next due date must be in the future and ARMember's entitlement checks must confirm valid access. A primary account status of Active alone is insufficient. Keep next-payment and finite-agreement expiry dates distinct.

## Payment handling

### Create mode

Use `payment.mode: create` when no corresponding payment is already recorded. Require:

- `reference`: a sponsor allocation reference unique to this member's payment.
- `sponsor_name`: the actual external payer/sponsor.
- `amount`: positive decimal string, not a floating-point JSON number.
- `currency`: ISO currency code matching the subscription/site currency.
- `paid_at`: received-payment timestamp with timezone offset.

For a sponsor bank transfer covering multiple members, use one distinct allocation reference per member. The common bank-transfer reference can additionally be kept in audit metadata.

Create a payment log with the correct user and plan, gateway `manual`, mode `manual_subscription`, normalized status `success`, supplied reference as `arm_transaction_id`, and actual amount/currency/payment date. Preserve the processing timestamp separately. Include sponsor, operator, operation ID, and covered cycle in structured metadata/notes.

The first implementation requires the supplied payment total and currency to match the stored cycle's calculated amount, including applicable tax. Reject discrepancies. Partial payments, discounts, split payments, and zero-fee sponsorship need an explicit separate policy.

Use ARMember's transaction primitive so its invoice identity and bookkeeping are generated correctly. Verify the returned payment-log ID and read the record back. Do not label the transaction as personally paid by the member.

### Existing-payment mode

Use `payment.mode: existing` with `payment_log_id` if the administrator already added the sponsor payment. This ID is the payment-log primary key, not an invoice number or gateway reference.

Validate that the record belongs to the same member and plan, represents a successful manual external payment, matches amount/currency, and has not already funded another renewal. Its purpose must establish that it covers this next cycle; do not assume the latest successful historical payment does.

Associate the existing payment with the cycle and operation. Do not insert, overwrite, or delete a second payment. Return the existing log ID with `created: false`.

### Duplicate-payment trap in the admin flow

Edit Member's Renew/Renew Cycle action already calls `arm_add_manual_user_payment()`. Do not call the complete admin save flow and then add another sponsor transaction. The adapter must apply the necessary cycle/account transition and exactly one payment mode, preserving required ARMember bookkeeping without the default extra transaction.

## HTTP interface

### Preview or commit

`POST /wp-json/bono_armember/v2/members/{user_id}/renewals`

Headers:

- WordPress authentication through `Authorization` over HTTPS.
- `Content-Type: application/json`.
- `Idempotency-Key` required on commits: 16–128 printable ASCII characters without whitespace.

Create-payment preview:

```json
{
  "plan_id": 12,
  "dry_run": true,
  "payment": {
    "mode": "create",
    "reference": "DNB-2026-Q4-member-123",
    "sponsor_name": "Ducati Newport Beach",
    "amount": "30.00",
    "currency": "USD",
    "paid_at": "2026-11-13T09:00:00-08:00"
  }
}
```

Existing-payment preview:

```json
{
  "plan_id": 12,
  "dry_run": true,
  "payment": {
    "mode": "existing",
    "payment_log_id": 4567
  }
}
```

Example IDs and amounts are illustrative, not site configuration.

`plan_id` is a required positive integer. `dry_run` defaults to true. Payment modes are mutually exclusive; reject create-only fields in existing mode and vice versa. Reject caller-supplied start/expiry dates, arbitrary cycle counts, payment status, or billing-mode overrides.

Commit repeats the previewed command with `dry_run: false`, adds the preview's `expected_state` token, and includes `Idempotency-Key`. Bind the token to the actor, site, member, plan, canonical command, and relevant before-state. Expire it after 15 minutes. It does not replace authorization.

Dry run validates and returns projected dates, status, payment details, and whether an existing payment will be reused. It changes no account, subscription, transaction, invoice counter, membership history, or notification state. Persisting an expiring preview token is allowed.

### Preview response: HTTP 200

```json
{
  "dry_run": true,
  "can_execute": true,
  "expected_state": "opaque-server-issued-token",
  "expires_at": "2026-11-13T17:15:00Z",
  "user_id": 123,
  "plan_id": 12,
  "cycles_to_advance": 1,
  "before": {
    "primary_status": 2,
    "secondary_status": 0,
    "completed_cycles": 1,
    "next_due_at": "2026-10-01T00:00:00-07:00"
  },
  "after": {
    "primary_status": 1,
    "secondary_status": 0,
    "completed_cycles": 2,
    "next_due_at": "2027-10-01T00:00:00-07:00",
    "entitlement_active": true
  },
  "payment": {
    "mode": "create",
    "will_create": true,
    "reference": "DNB-2026-Q4-member-123",
    "amount": "30.00",
    "currency": "USD"
  },
  "notifications": {"policy": "suppressed"}
}
```

Return a real agreement expiry separately as `expires_at` inside before/after when applicable; do not substitute next due date for expiry. Top-level `expires_at` above is the preview-token expiry.

### Completed response: HTTP 201

Return `operation_id`, `state: completed`, `user_id`, `plan_id`, `cycles_advanced: 1`, verified actual before/after state, and payment `{mode, created, payment_log_id, invoice_id, reference, amount, currency}`. Include `idempotent_replay: false` and notification policy.

Set `Location: /wp-json/bono_armember/v2/renewals/{operation_id}`. A completed authorized replay returns the same result with HTTP 200 and `idempotent_replay: true`.

### Operation status

`GET /wp-json/bono_armember/v2/renewals/{operation_id}`

Require the same renewal and activation capabilities. Return the durable operation state and its result or reconciliation details. This lets a client recover after a timeout without creating another payment or renewal. Use `Cache-Control: no-store` for these responses.

## State transition and activation

After validating and locking the target member/plan:

1. Recheck the preview's relevant before-state and payment uniqueness.
2. Apply the scheduled cycle transition and selected create/link payment mode as one recoverable operation.
3. Use ARMember's account activation primitive, consistent with the existing Gateway activation path.
4. Clear nonpayment grace state and target-plan suspension where appropriate, without altering unrelated restrictions.
5. Read back and verify status, dates, cycle count, plan access, payment success, and cycle/payment association.
6. Mark the operation completed only when all three required outcomes are verified.

The implementation may choose the internal write order to preserve ARMember hooks and transaction consistency. It must not invoke a default admin-payment generator as an extra side effect.

## Idempotency, concurrency, and recovery

- Persist a durable operation journal in the database. Transients or request-local state are insufficient.
- Canonicalize the business command and fingerprint member, plan, payment mode/reference or existing log, sponsor, amount/currency, and received timestamp. Exclude transport-only preview token and dry-run fields.
- Same key and command returns the existing result; same key with a different command returns 409.
- Enforce uniqueness for sponsor allocation references, consumed existing payment IDs, and settled member/plan/cycle. A new idempotency key must not bypass these constraints.
- Serialize mutations per member/plan and recheck state under the lock. Concurrent requests for the same cycle must not both commit.
- Reauthorize replay requests. Resolve an existing operation before demanding a still-valid preview token, so token expiry after a client timeout does not cause a second mutation.
- Use journal states `in_progress`, `completed`, `failed_no_change`, and `needs_reconciliation`. Include actor, relevant IDs, intended cycle, before-state, and completed write steps. Never store credentials.
- Use database transactions only after verifying all affected storage and hooks. Rollback does not undo external mail/hooks or stale WordPress object-cache state. Invalidate/reconcile caches and defer external side effects appropriately.
- If rollback cannot be proven and a payment or cycle might have committed, return 503 with operation ID and known step outcomes. Block automatic re-execution and further settlement of that cycle until reconciled. Do not repair uncertainty by creating another transaction or advancing another period.
- A failed renewal using an existing payment must not delete or alter that historical payment.

## Notifications and caller integration

Suppress ARMember activation, renewal, and invoice delivery emails caused by this operation. The existing DROC Apps Script owns the reviewed VIP emails. Scope suppression to this operation; keep bookkeeping/history hooks and unrelated mail working.

Mailchimp tags and spreadsheet columns are outside this endpoint. The caller may proceed based on a completed, verified response. It must still set `communication = email` only after its email is actually sent, not merely after renewal succeeds.

## Error contract

Use WordPress's `{code, message, data: {status, operation_id?}}` envelope. Preview failures use the same errors and do not issue an executable token.

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `rest_invalid_param` | Invalid/missing/unknown fields, commit token, or idempotency key. |
| 401/403 | `rest_forbidden` | Authentication or capability failure. |
| 404 | `bono_arm_api_member_not_found`, `bono_arm_api_plan_not_found`, `bono_arm_api_payment_not_found` | Required record or assignment missing. |
| 409 | `bono_arm_api_state_changed` | Expired preview or relevant state changed. |
| 409 | `bono_arm_api_idempotency_conflict` | Same key with different command. |
| 409 | `bono_arm_api_payment_already_applied` | Sponsor reference or existing payment already consumed. |
| 409 | `bono_arm_api_renewal_in_progress` | Concurrent mutation; include operation ID and `Retry-After`. |
| 422 | `bono_arm_api_unsupported_subscription` | Unsupported mode, restriction, exhausted agreement, or invalid schedule. |
| 422 | `bono_arm_api_payment_mismatch` | Wrong member/plan, unsuccessful payment, amount/currency/purpose mismatch. |
| 422 | `bono_arm_api_still_overdue` | One cycle would not restore current entitlement; no changes. |
| 503 | `bono_arm_api_feature_disabled`, `bono_arm_api_armember_unavailable` | Feature or required compatible dependency unavailable. |
| 503 | `bono_arm_api_renewal_needs_reconciliation` | Partial/uncertain mutation; return operation ID and known outcomes. |

## Acceptance criteria

1. Inactive overdue manual annual member: one cycle, active account and entitlement, one successful sponsor transaction.
2. Already-active member: activation no-op, exactly one new cycle/payment.
3. October 1 annual anniversary remains October 1 when processed November 13.
4. Fixed monthly billing days, multi-year intervals, month ends, leap years, and DST follow the supported ARMember schedule calculator.
5. One cycle that still leaves access overdue: explicit rejection, no activation or payment, no hidden catch-up.
6. Existing successful matching payment: renewal completes with that ID and no new payment.
7. Wrong user/plan, pending/failed payment, mismatched amount/currency, or already-consumed payment: reject without mutation.
8. Same command/key after success, response loss, or preview expiry: same operation/payment IDs and cycle count.
9. Same reference/payment with a new key and concurrent requests with different references: at most one settlement per cycle.
10. State changed since preview: reject before business writes.
11. Failure at each activation, cycle, or payment step: verified rollback or reconciliation state; retry never duplicates payment or cycle.
12. No deletion/alteration of a pre-existing payment when renewal fails.
13. No changes for unauthorized callers, disabled feature, unavailable ARMember, auto-debit, unsupported restrictions, or invalid schedule.
14. Dry run makes no business or notification changes and does not increment an invoice counter.
15. No default extra admin-paid transaction and no activation/renewal/invoice emails; unrelated mail still works.
16. Stored payment includes accurate sponsor amount/date/reference, successful status, invoice identity, operator, and covered cycle.
17. Existing routes, payment reads, activation/deletion, capability upgrades, packaging, and release gates remain compatible.

## Verified source behavior and implementation limits

Inspected the supplied `wpdrocdesmo.com/armember.zip`, ARMember Pro 8.1.1. Source paths below are inside that archive:

- `armember/core/classes/class.arm_member_forms.php:7467–7541`: admin cycle renewal updates the cycle count/date, clears nonpayment grace/suspension, and calls `arm_add_manual_user_payment`; paid-finite plans have a separate path.
- `armember/core/classes/class.arm_members.php:6361`: `arm_get_next_due_date` derives the schedule from the stored start, completed cycles, configured interval/day, and selected cycle.
- Same file, `arm_add_manual_user_payment` around line 8366: generates the default admin-paid transaction. It does not implement this sponsor reference/amount contract.
- `armember/core/classes/class.arm_transaction.php:1017`: standalone Add Manual Payment records the payment without performing the Edit Member renewal transition.
- `armember/core/classes/class.arm_api_service_feature.php:753` and `:846`: native add-membership and add-transaction routes are separate. They do not implement this combined contract.
- `armember/core/classes/class.arm_subscription_plans.php:1705`: the native add-membership API uses a subscription-update path distinct from the admin Renew Cycle handler. Do not assume identical side effects.

The actual recurring date helper was run in isolation with annual, monthly fixed-day, multi-year, and severely overdue fixtures. That validates those sample date calculations only. The API now has local WordPress integration tests, including isolated native date, selected-cycle pricing and transaction helpers when licensed source is supplied. Full live-site reactivation, third-party hooks, subscription-table synchronization, notification delivery, and staging validation against the supported dependency remain unverified. The implementation uses durable conservative reconciliation rather than claiming transaction atomicity.

## Unsupported-subscription diagnostics

Responses retain the existing 422 code and add `data.reason`: `plan_inactive`,
`plan_not_recurring`, `payment_mode_not_manual`, `subscription_cancelled`, `plan_change_pending`,
`account_restricted`, `administrator_member`, `unrelated_grace_action`, `other_plan_restriction`,
`suspension_reason_unverified`, `confirmed_suspension_not_overdue`, `invalid_schedule_field`,
`subscription_not_started`, `payment_cycle_missing`, `billing_interval_invalid`,
`recurrence_limit_invalid`, `recurrence_exhausted`, `schedule_dates_invalid`, `billing_day_invalid`,
`due_date_not_advanced`, `entitlement_denied`, `cycle_amount_invalid`,
`tax_configuration_unsupported`, or `tax_rate_invalid`. `invalid_schedule_field` also provides
`data.field` (the field name only). These diagnostics do not expose raw metadata or credentials.
