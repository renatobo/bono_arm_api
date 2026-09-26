# ARMember fixtures

REST integration tests deliberately avoid bundling proprietary ARMember code.

- `armember-stubs.php` holds hand-written fakes for the two ARMember entry points the plugin calls: `arm_set_member_status()` and the members manager's pre- and post-delete cleanup methods. `tests/bootstrap.php` loads them before the plugin, so `Dependency::is_met()` is true in the suite.
- `ArmemberFixtureTest.php` creates minimal `arm_payment_log` and `arm_members` tables in `set_up()`. The WordPress test suite rewrites `CREATE TABLE` into temporary tables, which `SHOW TABLES` does not list, so the tests prime the table-availability transient instead of relying on the schema probe. Dependency-unavailable behaviour is covered by priming that transient with `exists => false`.

A licensed ARMember installation can be supplied in an external integration environment to exercise its real payment tables, mailer, and deletion hooks.
