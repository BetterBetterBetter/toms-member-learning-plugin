# Accountability Group Join Flow From Export

This document summarizes the accountability-related code found in this exported site folder:

```text
C:\Users\elike\Downloads\site-archive-tomschoolofdev-live-1781496331-X0l0l7I9Gs7EgS4G9jjj1FlR6d3lv5dFKesb
```

It is meant to be compared with `ACCOUNTABILITY_JOIN_FLOW.md`, which describes the current workspace copy.

## Summary

The exported site has two accountability-related systems:

1. `wp-content/plugins/tsol-accountability-groups`
   - This is the same core accountability group join/transfer plugin.
   - In the export, the plugin header says version `2.0.0`.
   - The browser, shortcode, AJAX, Groups plugin, ACF, and MemberPress parts are mostly the same as the current workspace.
   - The major change is downstream sync: the export sends group-change webhooks to the Access platform instead of directly to n8n.

2. `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal`
   - This is a separate accountability intake modal feature.
   - It does not join the user to a group directly.
   - It asks logged-in users intake questions, lets them choose available calls, and stores the answers as user meta for admin review.
   - This feature does not exist in the current workspace copy I checked.

## Core Join Flow In The Export

The normal group join path still starts on `/accountability/`.

1. The page uses the `[user_accountability_groups]` shortcode.
2. The shortcode loads `styles/accountability-groups.css` and `js/accountability-groups.js`.
3. The shortcode queries:
   - MEC events with `event_group` post meta.
   - Groups plugin groups under parent group ID `2`.
   - The current user's existing accountability group membership.
4. The shortcode outputs:

```js
window.accountabilityData = {
  userId,
  eventMap,
  userEvents
};
```

5. Frontend JavaScript attaches event, group, and user IDs to MEC event buttons.
6. Buttons become `Join`, `Transfer`, `Joined`, or `Join Waitlist`.
7. Clicking `Join` or `Transfer` sends AJAX to WordPress.
8. PHP updates Groups plugin membership.
9. Groups plugin hooks fire.
10. The export sends the hook payload to Access.

## Files In The Export

Core group join files:

- `wp-content/plugins/tsol-accountability-groups/tsol-accountability-groups.php`
- `wp-content/plugins/tsol-accountability-groups/includes/accountability-page-shortcode.php`
- `wp-content/plugins/tsol-accountability-groups/js/accountability-groups.js`
- `wp-content/plugins/tsol-accountability-groups/styles/accountability-groups.css`

Additional accountability modal files:

- `wp-content/plugins/tomschooloflife-plugin/tomschooloflife-plugin.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/class-plugin.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal-repository.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal-renderer.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal-settings.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal-submission-handler.php`
- `wp-content/plugins/tomschooloflife-plugin/includes/features/accountability-modal/class-accountability-modal-admin.php`
- `wp-content/plugins/tomschooloflife-plugin/assets/features/accountability-modal/accountability-modal.js`
- `wp-content/plugins/tomschooloflife-plugin/assets/features/accountability-modal/accountability-modal.css`

## Same As Current Workspace

The exported `tsol-accountability-groups` plugin keeps these same mechanics:

- Same shortcode:

```text
[user_accountability_groups]
```

- Same AJAX action:

```text
join_accountability_group
```

- Same nonce action:

```text
accountability_groups_nonce
```

- Same WordPress AJAX endpoint:

```text
/wp-admin/admin-ajax.php
```

- Same backend controller:

```php
AccountabilityGroupsHandler::joinAccountabilityGroupAjaxController()
```

- Same Groups plugin operations:

```php
Groups_User_Group::delete($userId, $groupId);
Groups_User_Group::create([
    'user_id' => $userId,
    'group_id' => $incomingGroupId
]);
```

- Same single-group policy:
  - A user can only be in one accountability group at a time.
  - Joining a new group removes the old accountability group first.

- Same Groups plugin hooks:

```text
groups_created_user_group
groups_deleted_user_group
```

- Same ACF event-group field behavior:

```text
acf/load_field/name=event_group
```

- Same MemberPress cancellation hook:

```text
mepr_subscription_status_cancelled
```

- Same group hierarchy assumption:

```sql
SELECT group_id FROM wp_groups_group WHERE parent_id = 2;
```

- Same MEC event relationship:

```sql
SELECT post_id FROM wp_postmeta WHERE meta_key = 'event_group' AND meta_value = $groupId;
```

- Same waitlist Google Form behavior when the event has `group_full` set.

## Main Difference: Access Instead Of n8n

The current workspace copy sends webhook payloads directly to n8n:

```text
https://n8n.tomschooloflife.com/webhook/0d451b15-598b-408e-9192-e8569075d318
```

The exported plugin says version `2.0.0` and sends to the Access platform instead:

```text
https://access.tomwoods.com/api/webhooks/accountability-group
```

In the export, the webhook URL can come from:

```php
ACCESS_WEBHOOK_URL
```

or:

```php
get_option('tsol_access_webhook_url', 'https://access.tomwoods.com/api/webhooks/accountability-group')
```

The webhook secret can come from:

```php
ACCESS_WEBHOOK_SECRET
```

or:

```php
get_option('tsol_access_webhook_secret', '')
```

If a secret is configured, the export adds:

```http
Authorization: Bearer <secret>
```

The current workspace n8n version does not add Authorization.

## Export Webhook Function

The export replaces the old `sendToN8N()` behavior with:

```php
sendToAccess($data)
```

There is still a legacy private method:

```php
sendToN8N($data)
```

but it only proxies to:

```php
return $this->sendToAccess($data);
```

## Export Webhook Behavior

The exported `sendToAccess()` differs from the current `sendToN8N()` in several important ways:

- It skips the webhook if there is no RocketChat ID in either the incoming or outgoing group payload.
- It uses a `30` second timeout instead of `15`.
- It uses `sslverify => true`; the current n8n version uses `sslverify => false`.
- It supports an Authorization bearer token.
- It treats HTTP `200` and `207` as success.
- It logs the outgoing payload and Access response.
- It sends an admin email on webhook failure.

## Export Join Payload

When a user joins an accountability group, the exported payload shape is still basically the same:

```js
{
  userId,
  name,
  email,
  incomingGroup: {
    id,
    eventId,
    eventTitle,
    rocketchatId,
    googleCalendarId,
    facilitator,
    zoomLink
  },
  outgoingGroup: []
}
```

That payload is sent to Access, not n8n.

## Export Leave Payload Difference

The current workspace version sends `outgoingGroup` as a single object:

```js
{
  incomingGroup: [],
  outgoingGroup: {
    id,
    eventId,
    eventTitle,
    rocketchatId,
    googleCalendarId,
    facilitator
  }
}
```

The export sends `outgoingGroup` as an array containing one object:

```js
{
  incomingGroup: [],
  outgoingGroup: [
    {
      id,
      eventId,
      eventTitle,
      rocketchatId,
      googleCalendarId,
      facilitator
    }
  ]
}
```

That is a meaningful contract difference. Any receiver expecting the old n8n object shape may not handle the export shape unless it supports both.

## Export Admin Settings For Core Group Plugin

The exported `tsol-accountability-groups` plugin adds a WordPress settings page:

```php
add_options_page(
    'Accountability Groups Settings',
    'Accountability Groups',
    'manage_options',
    'tsol-accountability-groups',
    [$this, 'renderSettingsPage']
);
```

That settings page lets admins configure:

- Access Platform Webhook URL
- Webhook Secret Token

The current workspace copy has a hard-coded n8n webhook URL and no settings page for this plugin.

## Export JavaScript Difference

The export's `accountability-groups.js` is almost the same as the current workspace, but `getEventDayName()` was changed.

Current workspace behavior:

```js
return daysOfWeek[date.getDay()];
```

Export behavior:

```js
const mondayIndex = (date.getDay() + 6) % 7;
return daysOfWeek[mondayIndex];
```

This matters because JavaScript's `date.getDay()` returns Sunday as `0`, Monday as `1`, and so on. The array in the plugin starts with Monday. The export remaps the index so Monday-first labels line up correctly.

The export also adds basic invalid-date handling:

```js
if (month === -1 || isNaN(day)) return null;
```

## Stale Documentation In The Export

The export's `README.md` and `readme.txt` inside `tsol-accountability-groups` still describe the old n8n behavior.

Examples:

- They refer to n8n as the external webhook workflow.
- They list the n8n webhook URL.
- `readme.txt` still says stable tag `1.0.0`.

But the actual exported PHP code says plugin version `2.0.0` and sends to Access.

So for the export, trust the PHP code over those readme files.

## Additional Export Feature: Accountability Modal

The export includes a separate plugin:

```text
wp-content/plugins/tomschooloflife-plugin
```

Its plugin header says:

```text
Plugin Name: Tom's School Of Life Plugin
Version: 0.1.4
Author: Thrice Agency
```

It requires Access Platform SSO before it fully initializes.

Inside `includes/class-plugin.php`, it registers this feature:

```php
new TSOL_Accountability_Modal()
```

That accountability modal is separate from the direct group join buttons.

## What The Modal Does

The modal is an intake form for logged-in users who are eligible to see it.

It can:

- Auto-open on selected content, usually the accountability page.
- Ask configurable questions.
- Show open accountability calls.
- Save unfinished answers in browser local storage.
- Show a floating resume launcher if the user closes the modal.
- Submit answers over AJAX.
- Store the submitted answers as WordPress user meta.
- Let admins review submissions later.

It does not call `Groups_User_Group::create()`.

It does not join the user to an accountability group.

It does not send the group-change webhook.

## Modal Display Rules

The modal can show when all of these are true:

- The site plugin is enabled.
- The accountability modal display setting is enabled.
- The visitor is logged in.
- The request is not admin, AJAX, REST, or JSON.
- The current content is one of the configured target page IDs.
- The user is not already in an accountability group, if `hide_if_current_member` is enabled.
- The user has not already completed the intake, if `hide_after_submission` is enabled.

Default display rules include:

```php
'enabled' => '1',
'scroll_threshold' => 320,
'hide_if_current_member' => '1',
'hide_after_submission' => '1',
'show_admin_bar_button' => '1',
'save_draft_answers' => '1',
'draft_ttl_days' => 7,
'show_resume_launcher' => '1',
'launcher_position' => 'bottom_right',
'animate_resume_launcher' => '1',
```

The default target page is found by:

```php
get_page_by_path('accountability')
```

## Modal Questions

The default modal asks:

- Professional goals
- Personal goals
- Why they joined TSoL
- Occupation / day-to-day
- Which weekly accountability calls they could realistically attend

The modal content is configurable in the admin.

Supported question types include:

- Text field
- Text area
- Number
- Range
- Select
- Checkbox
- Radio
- Joinable accountability calls

## Modal Joinable Calls

The modal gets open calls from MEC events linked to accountability groups.

It queries:

- Published `mec-events`
- Events with `event_group` post meta
- Groups under parent group ID `2`
- Events where `group_full` is not truthy

This means full/waitlist events are excluded from the modal's call choices.

The returned call data includes:

```js
{
  event_id,
  group_id,
  event_title,
  group_name,
  label
}
```

## Modal AJAX Submission

The modal JavaScript submits to WordPress AJAX with:

```text
action=tsol_accountability_modal_submit
```

The PHP hook is:

```php
add_action('wp_ajax_tsol_accountability_modal_submit', array($this, 'handle_submission'));
```

The nonce action is:

```text
tsol_accountability_modal
```

On submission, PHP verifies:

- The nonce is valid.
- The user is logged in.
- The user is not already in an accountability group.
- Required answers are present.
- Selected calls are still valid joinable calls.

## Modal User Meta

Successful modal submissions are stored as user meta:

```text
tsol_accountability_intake_completed
tsol_accountability_intake_professional_goals
tsol_accountability_intake_personal_goals
tsol_accountability_intake_tsol_reason
tsol_accountability_intake_occupation
tsol_accountability_intake_available_calls
tsol_accountability_intake_answers
tsol_accountability_intake_submitted_at
```

Again, this is only intake storage. It is not group enrollment.

## Modal Admin UI

The export adds a TSOL admin menu with an Accountability Modal submenu.

The modal admin UI includes:

- Overview stats
- Intake submissions
- Submission filtering
- CSV export
- Display rule settings
- Content/question builder
- Delete submission action

The submission status is based on whether the user currently has an accountability group:

- `Already in a group`
- `Awaiting group placement`

## Complete Export Join Sequence

```text
User visits /accountability/
    -> [user_accountability_groups] shortcode runs
    -> Shortcode queries MEC events, Groups plugin groups, and current user membership
    -> Shortcode outputs window.accountabilityData
    -> accountability-groups.js runs
    -> JS attaches event/group/user data to MEC buttons
    -> User clicks Join or Transfer
    -> JS posts to /wp-admin/admin-ajax.php
    -> action=join_accountability_group
    -> joinAccountabilityGroupAjaxController() runs
    -> Nonce is verified
    -> Group ID is validated
    -> Existing accountability group is removed if needed
    -> User is added to the requested Groups plugin group
    -> Groups plugin fires groups_deleted_user_group if transferring
    -> Groups plugin fires groups_created_user_group for the new group
    -> Plugin builds outgoingGroup and/or incomingGroup payloads
    -> sendToAccess() posts JSON to Access
    -> Access handles RocketChat/group sync
    -> Frontend shows success message
```

## Complete Export Modal Sequence

```text
User visits selected target content, usually /accountability/
    -> Tom's School Of Life Plugin loads
    -> Access SSO dependency check passes
    -> TSOL_Accountability_Modal initializes
    -> Display rules are checked
    -> User is logged in and not already in a group
    -> Modal assets are enqueued
    -> Modal markup is rendered in wp_footer
    -> User scrolls past threshold or admin opens manually
    -> User answers intake questions
    -> User selects joinable weekly calls
    -> JS posts action=tsol_accountability_modal_submit
    -> PHP validates nonce, login, answers, and selected calls
    -> PHP stores answers as user meta
    -> Admin can review the submission later
```

## Differences To Watch

- The export sends group-change webhooks to Access, while the current workspace sends directly to n8n.
- The export adds configurable webhook URL and secret settings.
- The export's webhook request can be authenticated with `Authorization: Bearer <secret>`.
- The export accepts HTTP `207` as a success response.
- The export skips webhooks when no RocketChat IDs are present.
- The export's outgoing group payload is an array, not a plain object.
- The export has a corrected Monday-first event date mapping in JavaScript.
- The export includes a separate accountability intake modal feature.
- The intake modal stores preferences but does not join users to groups.
- The export's readme files are stale and still describe n8n even though the PHP code uses Access.

