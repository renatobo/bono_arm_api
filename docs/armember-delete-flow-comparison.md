# ARMember Delete Flow Comparison

This document explains the note in this repository:

> prefer ARMember's safer pre-delete and post-delete cleanup flow around `wp_delete_user()` instead of mirroring the current UI delete path literally.

The short version is:

- ARMember's visible admin UI delete handler is only part of the real deletion flow.
- The important cleanup is split across WordPress hooks that run before and after `wp_delete_user()`.
- A future API route should preserve that full lifecycle instead of copying only the admin AJAX controller's visible statements.

## Scope and Sources

This comparison is based on:

- this plugin's note in [AGENTS.md](/Users/renatobo/development/bono_arm_api/AGENTS.md)
- this plugin's forward-looking delete-endpoint notes in [README.md](/Users/renatobo/development/bono_arm_api/README.md) and [readme.txt](/Users/renatobo/development/bono_arm_api/readme.txt)
- the current plugin zips in `temp/`:
  - `temp/armember.zip`
  - `temp/armember-membership.zip`

The primary comparison below uses the current Pro archive in `temp/armember.zip`.
The Lite zip in `temp/armember-membership.zip` was also checked for parity.

The exact current Pro line numbers are:

- hook registration in `armember/core/classes/class.arm_common_hooks.php:79-80`
- pre-delete hook handler in `armember/core/classes/class.arm_members.php:2915-2949`
- post-delete hook handler in `armember/core/classes/class.arm_members.php:2951-2981`
- admin single-delete AJAX path in `armember/core/classes/class.arm_members.php:3175-3188`
- admin bulk-delete AJAX path in `armember/core/classes/class.arm_members.php:3235-3254`
- front-end close-account path in `armember/core/classes/class.arm_shortcodes.php:4688-4716`

## The Two Processes

### 1. The literal current UI delete path

This is the code an implementer sees first in ARMember's admin member-delete AJAX handlers.

Single delete:

1. Check capability and request validity.
2. Load WordPress user functions.
3. On multisite, call `remove_user_from_blog()` and store an `arm_site_{blog_id}_deleted` marker.
4. On single-site, call `wp_delete_user( $id, 1 )`.
5. After `wp_delete_user()`, explicitly delete only `arm_login_history` rows for that user.
6. Return a success message.

Bulk delete:

1. Validate the request and permissions.
2. Iterate over selected users.
3. On multisite, call `remove_user_from_blog()`.
4. On single-site, call `wp_delete_user( $id, 1 )`.
5. After each deletion, explicitly delete only `arm_login_history`.
6. Return a bulk success message.

Important detail: this visible UI code is not the whole cleanup story.

### 2. ARMember's safer pre-delete and post-delete lifecycle

ARMember also registers two hooks:

- `delete_user` -> `arm_before_delete_user_action()`
- `deleted_user` -> `arm_after_deleted_user_action()`

That means a normal `wp_delete_user()` call triggers a larger lifecycle:

1. `delete_user` fires before the WordPress user is removed.
2. ARMember pre-delete logic runs.
3. WordPress deletes the user.
4. `deleted_user` fires after deletion.
5. ARMember post-delete logic runs.

This is the lifecycle the repo note is referring to.

## What ARMember Actually Does In Each Phase

### Pre-delete phase: `arm_before_delete_user_action()`

Before deletion, ARMember:

- triggers `do_action( 'arm_delete_users_external', $id )`
- reads `arm_user_plan_ids`
- loads each membership plan's effective data
- reconstructs the plan object either from stored plan detail or from the plan id
- checks whether each plan is recurring
- triggers `do_action( 'arm_cancel_subscription_gateway_action', $id, $plan_id )` for recurring plans
- triggers `do_action( 'arm_cancel_except_recurring_subscription_action', $id, $plan_id )` for non-recurring plans
- deletes some membership-state usermeta:
  - `arm_user_suspended_plan_ids`
  - `arm_changed_expiry_date_plans`

Why this matters:

- both recurring and non-recurring membership-linked cleanup can run before the WordPress user disappears
- doing this pre-delete preserves enough context to identify the user's plan and billing state

### Post-delete phase: `arm_after_deleted_user_action()`

After deletion, ARMember cleans up or anonymizes ARMember-specific data:

- deletes rows from:
  - `arm_members`
  - `arm_login_history`
  - `arm_activity`
  - `arm_entries`
  - `arm_fail_attempts`
  - `arm_lockdown`
- updates `arm_payment_log` rows for the deleted user by:
  - setting `arm_user_id = 0`
  - clearing `arm_payer_email`
- clearing `arm_first_name`
- clearing `arm_last_name`
- additionally clears bank-transfer/payer personal fields in `arm_payment_log`:
  - `arm_bank_name`
  - `arm_account_name`
  - `arm_additional_info`

Why this matters:

- ARMember intentionally does not hard-delete payment-log history
- it keeps transactional records while severing the link back to the deleted user
- this is safer than deleting payment history outright and more complete than removing only the WordPress user row

## Detailed Comparison

| Area | Literal UI path | Safer ARMember lifecycle |
| --- | --- | --- |
| Subscription cancellation | Not visible in the AJAX handler itself | Explicitly handled in the pre-delete hook for recurring and non-recurring cleanup paths |
| Timing of cancellation | Implicit via `wp_delete_user()` hook side effects | Deliberately before user deletion |
| ARMember table cleanup | Mostly not visible in the AJAX handler | Explicitly handled in the post-delete hook |
| Payment-log handling | Not handled in the visible UI code | User references are anonymized, not deleted |
| Login history cleanup | Manually deleted in the UI code | Also deleted in the post-delete hook |
| Multisite behavior | Removes user from current blog, not necessarily full user deletion | The documented repo note is about full deletion around `wp_delete_user()` |
| Clarity for API implementation | Misleading if copied literally | Matches ARMember's actual lifecycle and data invariants |

## Why Copying The UI Handler Literally Is Risky

If someone copied only the admin AJAX delete controller into a new REST route, they would likely reproduce the wrong abstraction.

Problems:

- The controller makes it look like deletion is just `wp_delete_user()` plus a login-history delete.
- In reality, the important ARMember cleanup lives in hook callbacks, not in the visible UI action.
- The UI code contains redundant cleanup for `arm_login_history`, because the post-delete hook already deletes it.
- The multisite branch uses `remove_user_from_blog()`, which is not the same thing as deleting the WordPress user entirely.
- A literal copy can encourage incomplete cleanup if `wp_delete_user()` is ever replaced, bypassed, or wrapped incorrectly.

## The Most Important Implementation Nuance

There is one subtle but critical point:

- `wp_delete_user()` already triggers ARMember's `delete_user` and `deleted_user` hooks.
- Because of that, a future route should not blindly call `arm_before_delete_user_action()` and `arm_after_deleted_user_action()` manually around `wp_delete_user()` while those hooks are still attached.

If it did, cleanup could run twice.

Potential duplicate side effects:

- duplicate gateway cancellation attempts
- duplicate deletes against ARMember tables
- duplicate anonymization updates against payment logs

Some duplicate SQL deletes are probably harmless. Duplicate gateway cancellation is the more meaningful risk.

## Practical Meaning For This REST Delete Route

For a route like:

`POST /wp-json/bono_armember/v1/members/{user_id}/delete`

the correct interpretation of the repo note is:

- preserve ARMember's full pre-delete and post-delete lifecycle
- do not model the route after the admin UI controller line-for-line
- rely on `wp_delete_user()` only when ARMember's hooks are active and you want the normal lifecycle to fire
- if you ever need explicit orchestration, avoid double-running the hook handlers

In practice, on a normal single-site install where ARMember is active, the safest route shape is usually:

1. Validate admin access and route enablement.
2. Validate the target user and any guardrails such as "do not delete current admin" if desired.
3. Call `wp_delete_user( $user_id, 1 )`.
4. Let ARMember's registered `delete_user` and `deleted_user` hooks perform the pre/post cleanup.
5. Return a response that reflects deletion plus ARMember cleanup having been triggered.

That approach is safer than reproducing the UI controller's extra direct SQL statements because it stays aligned with ARMember's own lifecycle model.

## Implementation Checklist

For this repository's delete route, the implementation checklist is:

1. Gate the route behind its own plugin setting instead of reusing the activation toggle.
2. Keep the public route name as `POST /wp-json/bono_armember/v1/members/{user_id}/delete`.
3. Keep administrator-only access through the existing permission callback model.
4. Validate `user_id` and fail cleanly when the user does not exist.
5. Refuse multisite deletes unless multisite semantics are explicitly designed and documented.
6. Require ARMember to be loaded enough that its delete lifecycle is available.
7. Prefer `wp_delete_user( $user_id, 1 )` when ARMember's `delete_user` and `deleted_user` hooks are attached.
8. Use a guarded manual fallback only if ARMember's pre/post-delete methods are callable but the hooks are not attached.
9. Never manually run ARMember pre/post-delete methods when the hooks are already active.
10. Keep the existing JSON envelope shape so external callers do not need a different parser.
11. Update `README.md`, `readme.txt`, and checked-in API specs together when the route ships.

## Related But Different Flow: Close Account

ARMember's front-end close-account flow is related but not identical.

Before it deletes the user, it also:

- marks plans as cancelled
- writes membership history entries
- triggers `arm_cancel_subscription`
- clears user plan detail

Then it calls `wp_delete_user()`.

That flow is more opinionated than the admin delete flow because it is a user-facing account-closure workflow, not a generic admin delete endpoint. A future Bono ARM API delete route should not assume it needs to copy the close-account semantics unless those business rules are explicitly desired.

## Recommended Conclusion

The note in this repository is directionally correct.

The delete endpoint should be designed around ARMember's real deletion lifecycle:

- pre-delete subscription cleanup
- `wp_delete_user()`
- post-delete ARMember cleanup and payment-log anonymization

It should not be implemented as a line-for-line clone of the current admin UI delete handler, because that handler exposes only a thin controller layer and hides most of the important behavior in hooks.
