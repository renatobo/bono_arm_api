# Reliability review disposition

This is the disposition of the November 2026 review, not a production certification.

| Finding | Disposition |
| --- | --- |
| 1. No resolution path | Added administrator reconciliation REST action, member locking, immutable repeated-resolution semantics, operator/time/outcome audit, and preserved payment reservations. It records explicit attestation after external inspection/repair; it does not perform repair. |
| 2. Opaque verification | Split verification into named, persisted checks; journal failures include checkpoint and exception class, excluding exception messages. Roles-change regression asserts the specific failure name. |
| 3. Live retry reported as uncertain | Same-key retry probes member lock; live operations return 409 with Retry-After, abandoned operations remain blocked. Separate-connection regression covers live retry and concurrent resolve. |
| 4. Status toggle/schema behavior | Documented capability-gated status/reconciliation exception. Status without schema returns 404 and never installs it. |
| 5. Entitlement precedes payment | Payment is recorded and validated before cycle mutation. Injected cycle failure leaves payment recorded and entitlement unchanged. |
| 6. Badge controller layering | Moved vendor calls, metadata mutation and locking into Badge_Gateway; REST controller handles permissions, toggle and response wrapping. |
| 7. Gateway database dependency | Moved payment SQL interception into Renewal_Repository. |
| 8. File-wide suppression | Replaced with paired, justified method-local database suppression blocks. |
| 9. Location | Uses rest_url; spec describes absolute URL and custom/plain routing. |
| 10. Self-renewal | Explicit actor/target exclusion with delegated-capability regression. |
| 11. Current-user swap | Retained intentionally. Pro 8.1.1 arm_check_content_hasaccess uses get_current_user_id and applies subject-specific filters; checking as the operator would grant administrator bypass. Finally restores identity and removes temporary metadata filter. Exception regression and native entitlement tests added. Third-party filters run in subject context and require staging validation. |
| 12. Personal-data retention | Added WordPress exporter/eraser. Terminal member payloads and requesting operator IDs can be removed while financial reservation identifiers remain. Unresolved records retain evidence pending reconciliation. This is minimization, not complete anonymization or a universal retention policy. Sponsor requests and requests after account deletion still require operator handling; process member requests before account deletion. |
| 13. Beta stable tag | Restored last stable tag 2.2.0. Release preparation must synchronize the tag to the intended stable version; this development branch is not release-ready metadata. |

Removed the unimplemented failed_no_change state, documented resolved and erased replay
behavior, corrected status/catalog response lists and added the resolve contract. AGENTS.md
is the canonical guide; CLAUDE.md redirects there. Deployment-specific claims were removed.

Tests now include independent activation-toggle rejection, unauthenticated renewal, unknown
status/schema absence, live retry, resolution authorization/validation/state conflicts,
privacy retention/erasure, and isolated uninstall scenarios (single site, multisite, active
sibling, network sibling, and preloaded sibling constant).

Validation:

- PHP syntax and WordPress coding standards pass.
- Fixture mode: 80 tests, 369 assertions, two intentional native-source skips.
- Pro 8.1.1 native-helper mode: 80 tests, 389 assertions, no skips.
- Release-tool tests: 15 passed.
- Translation template regenerated with official WP-CLI.

Native mode now includes vendor subscription lookup, entitlement restriction and badge
achievement read-back, in addition to date/pricing/payment/activation helpers. Inspection of
Pro 8.1.1 class.arm_members_badges.php confirms admin awards append beneath the badge ID and
are stored serialized. Native reader tests consume the API-written representation.

Still required before production: full installed ARMember staging validation with the site's
hooks, payment/subscription tables, notifications, tax settings and access rules. Native
method extraction and fixture tables do not prove those interactions. No production changes,
release, or deployment were performed by this review.
