# ARMember fixtures

REST integration tests deliberately avoid bundling proprietary ARMember code.

- `armember-stubs.php` holds hand-written fakes for the two ARMember entry points the plugin calls: `arm_set_member_status()` and the members manager's pre- and post-delete cleanup methods. `tests/bootstrap.php` loads them before the plugin, so `Dependency::is_met()` is true in the suite.
- `ArmemberFixtureTest.php` creates minimal `arm_payment_log` and `arm_members` tables in `set_up()`. The WordPress test suite rewrites `CREATE TABLE` into temporary tables, which `SHOW TABLES` does not list, so the tests prime the table-availability transient instead of relying on the schema probe. Dependency-unavailable behaviour is covered by priming that transient with `exists => false`.

A licensed ARMember installation can be supplied in an external integration environment to exercise its real payment tables, mailer, and deletion hooks.

Sponsored renewals have separate doubles in `renewal-stubs.php` and integration tests in
`RenewalsTest.php`. These exercise real WordPress REST validation, capabilities, user metadata,
SQL journal reservations, MySQL advisory locks, and injected write/hook failures. The ordinary
CI suite uses a small annual-schedule/payment fake; it does not establish native ARMember parity.

To additionally run the supplied licensed Pro 8.1.1 date helper, selected-cycle pricing helper,
transaction primitive and account-status activation primitive, extract ARMember outside this repository and run:

```bash
BONO_ARMEMBER_SOURCE=/absolute/path/to/armember \
WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit
```

The test loader extracts only those trusted local method bodies at runtime. No vendor code is
checked in or distributed. The native date cases cover annual anniversaries, fixed billing days,
selected cycles, multi-year intervals, month ends, leap years and a DST boundary. The native
transaction primitive runs against isolated fixture tables. Other ARMember services remain test
doubles, so these tests do not establish full live-site lifecycle/hook compatibility. Run only
against a disposable WordPress test database. Before production enablement, verify the actual
site's ARMember hooks, subscription-table synchronization, notifications, tax settings and access
rules in staging. Never run the sponsor payment examples against production as a smoke test.

`BadgesTest.php` uses a small badge catalog double and the real WordPress user metadata APIs. It verifies native-style serialized manual assignments, preservation of other awards, idempotent add/remove, malformed state, access/feature/dependency guards, concurrent metadata updates and no-store headers. User-list results describe stored assignments rather than ARMember display eligibility.
