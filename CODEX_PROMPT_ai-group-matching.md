# Codex Task: AI-assisted accountability group matching & one-click join

You are working in the **Tom's School Of Life** WordPress plugin (`tomschooloflife-plugin`). Implement an AI-powered "find my group" experience that, at the end of the accountability intake modal, uses the **Gemini API** to recommend the **3 best-fit accountability groups** for the user (with a **"Show all groups"** fallback when none are a strong fit), and lets the user **join** a recommended group in one click, which actually enrolls them in the underlying itthinx Group.

Do not break existing behavior. Match the existing code style exactly (see "Conventions" below). Bump the plugin version and update the changelog when done.

---

## 1. Background — how the feature works today

Read these files before writing anything:

- `tomschooloflife-plugin.php` — bootstrap, constants, `require_once` list, version (`TSOL_SITE_PLUGIN_VERSION`, currently `0.1.4`).
- `includes/class-plugin.php` — feature registry (`register_features()`), admin menu, asset enqueue.
- `includes/class-admin-settings.php` — main settings page. **Already contains a Gemini API key option** (`TSOL_Site_Admin_Settings::GEMINI_API_KEY_OPTION` / `TSOL_Site_Admin_Settings::get_gemini_api_key()`). Reuse this; do not re-add a key field.
- `includes/contracts/interface-feature.php` — `TSOL_Site_Feature` interface (`init()`).
- `includes/features/accountability-modal/` — the whole feature:
  - `class-accountability-modal.php` — orchestrator; enqueues assets, renders modal in `wp_footer`, eligibility logic (`should_show_modal()`), localizes JS config.
  - `class-accountability-modal-settings.php` — options, default content, the question list (incl. the special `accountability_calls` question type), sanitizers.
  - `class-accountability-modal-repository.php` — **data access** (the important one). `get_joinable_calls()`, `get_joinable_call_map()`, `user_has_accountability_group()`, `get_submissions()`.
  - `class-accountability-modal-submission-handler.php` — AJAX submit (`wp_ajax_tsol_accountability_modal_submit`), validation, stores submission as user meta. **Note the meta key constants** (`META_*`).
  - `class-accountability-modal-admin.php` — admin tabs (Overview/Submissions/Display/Content), submission cards, the placeholder "Recommended fit" section.
  - `class-accountability-modal-renderer.php` — modal markup (multi-step form).
  - `assets/features/accountability-modal/accountability-modal.{js,css}` — front-end multi-step logic, draft saving, submit via `fetch`.

### Current data model (do not change the source-of-truth)

- **Accountability groups** are children of itthinx **Groups** parent group id `2` (filterable via `tsol_site_accountability_parent_group_id`). Table: `{$wpdb->prefix}groups_group` (columns include `group_id`, `parent_id`, `name`, `description`).
- Each group has one or more **calls** = `mec-events` posts linked to the group via postmeta `event_group` = `group_id`. A call is **joinable** when published, not `group_full` (meta truthy), and not a waitlist event. See the SQL in `get_joinable_calls()`.
- A "joinable call" row currently looks like:
  ```php
  array(
    'event_id'    => (int),   // mec-events post ID
    'group_id'    => (int),   // itthinx group id
    'event_title' => string,
    'group_name'  => string,
    'label'       => string,  // event_title, or group_name fallback
  )
  ```
- **Membership** is gated by `{$wpdb->prefix}groups_user_group` (see `user_has_accountability_group()`).
- On submit today, the handler stores intake answers + `selected_calls` to **user meta** and sets `META_COMPLETED = '1'`. **No group join happens and no AI runs.** Placement is manual in the admin.

### The separate enrollment engine — `tsol-accountability-groups` (READ THIS)

Joining is **already fully implemented by a separate, sibling plugin** called **`tsol-accountability-groups`** (v2.0.0, class `AccountabilityGroupsHandler`). It is **not in this repo** — it is deployed independently on the live site and runs on the same `/accountability/` page (it renders the `[user_accountability_groups]` shortcode with manual Join buttons). **Do not reimplement what it does. Do not modify it. Rely on it at runtime and degrade gracefully when it is absent.**

The single most important fact about it: **the Access/RocketChat/Google-Calendar sync is driven by the itthinx Groups hooks, NOT by its AJAX controller.** It registers:

```php
add_action('groups_created_user_group', [$this,'handleUserGroupCreationEvent'], 10, 2);
add_action('groups_deleted_user_group', [$this,'handleUserGroupDeletionEvent'], 10, 2);
```

`handleUserGroupCreationEvent()` builds the webhook payload (eventId, `rocketchat_id`, `google_calendar_id`, facilitator, zoom link) and POSTs it to the Access platform. **Therefore: any call to `Groups_User_Group::create(['user_id'=>…,'group_id'=>…])` for an accountability group automatically fires the full downstream sync — as long as `tsol-accountability-groups` is active.** You do NOT build any webhook. You just create the membership via the Groups API and the engine's hooks do the rest.

Its AJAX controller (`joinAccountabilityGroupAjaxController`, action `join_accountability_group`, nonce `accountability_groups_nonce`) does exactly this and nothing more: nonce check → validate the group id is a child of parent `2` → **delete the user's existing accountability group(s) first (single-group / transfer policy)** → `Groups_User_Group::create(...)`. Note it trusts a client-posted `userId` — **do not delegate to it**; do the equivalent yourself from your own endpoint using `get_current_user_id()` and your own nonce (more secure, self-contained).

Useful per-event metadata the engine reads (all on the linked `mec-events` post) that you can reuse for **matching signal and card display**:

| Data | Source |
|---|---|
| Facilitator **name** | postmeta `mec_organizer_id` → `wp_terms.name` (the engine's `getEventFacilitator()` maps to *emails*; for display/matching use the term **name**) |
| Zoom link | postmeta `mec_read_more` |
| RocketChat / Calendar IDs | postmeta `rocketchat_id`, `google_calendar_id` (sync only — you don't need these) |
| Event → group | ACF field `event_group` postmeta = `group_id` (one event per group is assumed) |

### The gap you are filling

1. After the user answers the intake questions, **call Gemini** to rank candidate groups by how well each group's bio (description + facilitator + event title) matches the user's stated goals/occupation/reason, **constrained to calls the user said they can attend**.
2. Show the **top 3** as join-able cards, plus a **"Show all groups"** button (and auto-show-all when no strong match exists).
3. When the user picks one and confirms, **join them** by calling the Groups API (`Groups_User_Group::create`) from your own validated endpoint — which makes `tsol-accountability-groups` perform the RocketChat/Access sync via its hooks — and record the choice. **Gate this on the engine being active** (see §2).

---

## 2. Decisions / assumptions (implement these; flag in PR description)

- **Availability is a hard filter, fit is the ranker.** Candidate set = joinable calls whose `event_id` the user selected in the `accountability_calls` step **and** that are still open at request time. Gemini only ranks/explains fit among those; it never overrides availability. If the user selected calls across multiple groups, dedupe to one best call per group before ranking.
- **"Show all" / no good fit:** If there are 0 candidates after the availability filter, or Gemini's best `fit_score` is below a configurable threshold (default `0.5`), the UI surfaces the "no strong match — here's everything open" state and the **Show all groups** list. The Show-all list = all currently joinable calls (not just the user's selected ones), grouped by group.
- **Group bio source:** Compose from the itthinx group's `description` column **plus the linked event's facilitator name and event title** (richer matching signal). Add an **admin-editable per-group bio override** stored in plugin options (so staff can write better matching blurbs without touching Groups). Resolution order for the base text: admin override → group `description` → group `name`; always append facilitator + event title when available. Expose a filter `tsol_site_accountability_group_bio`.
- **Joining goes through the Groups API and lets the engine sync.** Join = `Groups_User_Group::create(['user_id'=>$uid,'group_id'=>$gid])` called from your own validated endpoint. This fires `groups_created_user_group`, which `tsol-accountability-groups` handles to push the Access/RocketChat/Calendar sync. **Replicate the engine's single-group transfer:** before creating, `Groups_User_Group::delete()` any existing accountability-group membership for the user (so `groups_deleted_user_group` fires too and the prior group is cleanly synced). After joining, `user_has_accountability_group()` must return true.
- **The enrollment engine is a SOFT runtime dependency.** Before allowing a self-service join, require **both** `class_exists('Groups_User_Group')` **and** `class_exists('AccountabilityGroupsHandler')`. If the engine is missing, a `create()` would add the Groups row but **no RocketChat/Access sync would fire** — a broken half-join. So when the engine is absent: **do not self-join**; fall back to "store the intake + chosen preference and surface it for manual admin placement" (today's behavior), and tell the user their request was received. Do **not** add a raw-SQL insert fallback for joining — membership without the hook sync is worse than no membership.
- **MEC event booking is OUT OF SCOPE (confirmed).** Joining the itthinx **group** is the only thing required. The `group_full` flag is **set manually by staff**, NOT driven by MEC bookings, so a group-join does not create or touch any MEC booking/RSVP. Also fire `do_action('tsol_site_accountability_user_joined_group', $user_id, $group_id, $event_id)` after a successful join for future hooks.
- **Self-service join is immediate** (the user asked for "when they select one it triggers them joining it"). Re-validate server-side on join: user logged in, not already in an accountability group, the chosen call still open, the chosen group is a real child of parent group `2`, and the engine is active. Never trust the client.
- **AI matching is optional & degrades gracefully.** If no API key, AI disabled, or Gemini errors/times out, fall back to **availability-only ranking** (the user's selected open calls, original order) and still allow joining. Never block the user on Gemini.

---

## 3. What to build

### 3.1 Gemini client (shared service)

Create `includes/class-gemini-client.php` (general, reusable; not modal-specific):

- Class `TSOL_Gemini_Client`.
- `is_configured(): bool` — true when `TSOL_Site_Admin_Settings::get_gemini_api_key()` is non-empty.
- `generate_json(string $prompt, array $response_schema, array $args = []): array|WP_Error` —
  - POST via `wp_remote_post()` to `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`.
  - Auth via header `x-goog-api-key: <key>` (not query string).
  - Model from a filterable/option value, **default `gemini-2.5-flash`** (constant `DEFAULT_MODEL`). Make it overridable via option + filter `tsol_site_gemini_model`.
  - Request `generationConfig` with structured JSON output. **Pin the exact request shape against current Gemini docs at https://ai.google.dev/gemini-api/docs/structured-output** — the stable form is `responseMimeType: "application/json"` + `responseSchema: {...}`; newer API versions may use `responseFormat`. Implement what the current docs specify and keep it in one place. Set a low `temperature` (e.g. `0.2`) for stable ranking.
  - `timeout` ~15s (filterable). On non-200, transport error, or unparseable body → return `WP_Error` (do not throw).
  - Parse `candidates[0].content.parts[0].text`, `json_decode` to assoc array, return it. Validate it's an array.
- Add `require_once` in `tomschooloflife-plugin.php` **before** the feature classes.
- Keep zero hard dependency on the modal so the client can be reused later.

### 3.2 Repository additions (`class-accountability-modal-repository.php`)

Add methods (keep the existing SQL/`table_exists()`/filter style):

- `get_candidate_groups_for_calls(array $event_ids): array` — given the user's selected event IDs, return the still-open candidate **groups** (one best call per group), each:
  ```php
  array('group_id','group_name','event_id','event_title','label','bio')
  ```
  Reuse `get_joinable_call_map()` for openness; resolve `bio` via the bio resolver. Fetch group `description` in the existing joinable-calls query (add `g.description`) or a small helper. Add `facilitator` (from the event's `mec_organizer_id` → term name) to the row so the UI can show it.
- `get_all_joinable_groups(): array` — same shape, for **all** currently joinable calls (the Show-all list), deduped to best call per group.
- `get_group_bio(int $group_id, string $name, string $description, string $event_title = '', string $facilitator = ''): string` — base text via override option → `description` → `name`, then append facilitator + event title; finally pass through `tsol_site_accountability_group_bio` filter.
- `get_event_facilitator_name(int $event_id): string` — reads `mec_organizer_id` postmeta → `wp_terms.name` (the term **name**, not the engine's email mapping). Guarded/`null`-safe.
- `engine_is_active(): bool` — `class_exists('Groups_User_Group') && class_exists('AccountabilityGroupsHandler')`. Used to gate joining.
- `join_user_to_group(int $user_id, int $group_id): bool|WP_Error` — **(1)** bail with `WP_Error` if `!engine_is_active()`. **(2)** Find the user's existing accountability group memberships (groups whose `parent_id` = 2 that the user is in) and `Groups_User_Group::delete($user_id, $gid)` each (single-group transfer; fires the deletion sync). **(3)** `Groups_User_Group::create(['user_id'=>$user_id,'group_id'=>$group_id])` (fires the creation sync). Return true on success/already-member, `WP_Error` on hard failure. **No raw-SQL insert fallback** — see §2.
- `group_is_accountability_child(int $group_id): bool` — verify the group's `parent_id` equals the parent group id (used to validate join requests).

### 3.3 Matching service

Create `includes/features/accountability-modal/class-accountability-modal-matcher.php`:

- Class `TSOL_Accountability_Modal_Matcher` (constructed with the repository + a `TSOL_Gemini_Client`).
- `recommend(array $intake, array $selected_event_ids): array` returns a normalized result:
  ```php
  array(
    'mode'            => 'ai' | 'availability' | 'show_all', // how results were produced
    'has_strong_fit'  => bool,
    'recommendations' => array(  // up to 3
      array('group_id','event_id','label','group_name','reason','fit_score')
    ),
    'all_groups'      => array(  // for the Show-all view (lazy or included)
      array('group_id','event_id','label','group_name')
    ),
  )
  ```
- Flow:
  1. Build candidates via `get_candidate_groups_for_calls($selected_event_ids)`.
  2. If empty → `mode = show_all`, `has_strong_fit = false`, recommendations empty, `all_groups` populated.
  3. If Gemini configured/enabled → build the prompt + schema (below), call `TSOL_Gemini_Client::generate_json`, map results back to **trusted server-side data** (only `group_id`s that were in the candidate set — ignore any hallucinated ids), sort by `fit_score` desc, take top 3. Set `has_strong_fit = max(fit_score) >= threshold`.
  4. On any Gemini error/disabled → `mode = availability`: take the candidates in the user's selection order, top 3, `reason` = a static fallback string, `fit_score = null`, `has_strong_fit = true` (availability itself is a valid fit).
- **Gemini prompt contract** (keep deterministic and id-based):
  - System/instruction text: explain it's matching a member to the best **accountability group** based on the member's goals and each group's description; availability is already guaranteed; pick and rank the best fits; be honest with low scores when nothing fits.
  - Provide the member profile (the intake answers — goals, occupation, reason; pass labels + values, skip the calls answer) and a JSON array of candidate groups `{group_id, name, bio}` where `bio` already folds in description + facilitator + event title. **Never** send PII you don't need (no email; display name optional/omit).
  - **`responseSchema`** (enforce): object with `recommendations: array` of `{ group_id: integer, fit_score: number (0..1), reason: string (<= ~240 chars, member-facing, second person) }`. Require the model to only use provided `group_id`s.
  - Cap candidate count sent (e.g. first 25) to bound tokens.
- **Caching:** cache a recommendation result in a short-lived transient keyed by `user_id + hash(intake + sorted selected_event_ids + candidate signature)` (default 10 min, filterable) so re-renders/poll don't re-bill Gemini. Invalidate implicitly via the hash.

### 3.4 AJAX endpoints (`class-accountability-modal-submission-handler.php` or a small new handler)

Register in the feature's `init()` chain. All nonce-protected with the existing `NONCE_ACTION`, logged-in only.

- **Recommendations:** `wp_ajax_tsol_accountability_modal_recommend`
  - Input: the intake answers (same POST shape the submit handler already parses — **reuse `prepare_submission()` logic**; refactor the parsing out of `handle_submission()` into a shared private method so both endpoints validate identically) + `available_calls[]`.
  - **Persist the intake** here too (so we don't lose answers if the user abandons at the recommend step) OR keep the existing single submit and add recommend as a read-only step — **choose one and be consistent**; recommended: have `recommend` validate + store the submission (mark `META_COMPLETED` only after a successful *join*, but store answers/selected calls now), then return the matcher result. Re-check `user_has_accountability_group()` and short-circuit if already a member.
  - Output `wp_send_json_success($matcher_result)`.
- **Join:** `wp_ajax_tsol_accountability_modal_join`
  - Input: `event_id` (and/or `group_id`) of the chosen recommendation.
  - Re-validate, in order: logged in; **engine active** (`engine_is_active()`) — if not, return a payload telling the UI to show the "request received, a coach will place you" state instead of a hard error (the intake is already stored); not already in a group; group is an accountability child of parent `2`; the chosen call is currently joinable (re-query — guard the race where it filled up since recommendations were shown; return a friendly "that group just filled, here are others" payload, ideally with refreshed recommendations).
  - `join_user_to_group()` (does the transfer-delete + create; the engine's hooks sync to Access/RocketChat). On success: store chosen group/call to user meta (add new `META_*` constants, e.g. `META_JOINED_GROUP_ID`, `META_JOINED_EVENT_ID`, `META_JOINED_AT`), set `META_COMPLETED = '1'`, fire `do_action('tsol_site_accountability_user_joined_group', $user_id, $group_id, $event_id)`.
  - Output success with a confirmation message + the joined group label. On failure return actionable error.

### 3.5 Front-end (modal) changes

`class-accountability-modal-renderer.php` + `assets/features/accountability-modal/accountability-modal.{js,css}`:

- Add a **final "results" view** appended after the question steps (a non-form step, or a sibling panel toggled after submit). It has three states:
  1. **Loading** — "Finding your best-fit groups…" spinner while the recommend request is in flight.
  2. **Recommendations** — up to 3 cards: group label, the call time, **facilitator name** (when available), the Gemini `reason` (when present), and a **Join** button per card. A secondary **"Show all groups"** link/button.
  3. **No strong fit / Show all** — explanatory line + the full grouped list of open calls, each with Join.
- Wire the existing submit flow to the new endpoints:
  - On final submit, instead of (or in addition to) the current single submit, POST to `…_recommend`, render the results view from the JSON. Keep the existing draft-clear/launcher-hide behavior.
  - Join button → POST to `…_join` with the chosen `event_id`; on success show a confirmation state and close after a short delay (reuse the existing success/`setStatus` pattern). On "group full" error, re-render recommendations from the error payload if provided, else fall back to Show-all.
- Reuse existing patterns: `config` localized object (`window.tsolAccountabilityModal`), `fetch` with `credentials: 'same-origin'`, `data-tsol-accountability-modal-*` hooks, `setStatus`, focus management, reduced-motion handling. Add new localized strings + nonce-aware URLs (already present). Keep accessibility (focusable, aria-live for state changes).
- Localize new config: `recommendUrl`/action name, `joinUrl`/action name (both via `ajaxUrl` + action), strings (`findingFit`, `joinCta`, `showAll`, `noStrongFit`, `joinSuccess`, `joinError`, `groupFull`), and the fit threshold if any client logic needs it.

### 3.6 Admin

`class-accountability-modal-admin.php`:

- **Group bios editor:** a new section/tab (e.g. extend Content tab or add a "Groups" tab) listing each accountability-child group (id, name, current description) with a textarea to set the **bio override** saved to a plugin option. Sanitize as multiline text. This is what feeds matching quality.
- **AI matching settings:** small section (can live on the main settings page next to the API key, or in the modal's Display tab): toggle "Enable AI group matching", model select (default `gemini-2.5-flash`), fit-score threshold (0–1). Register via the existing settings API patterns; sanitize.
- Update the **Overview** "Recommendation approach" copy and the per-submission **"Recommended fit"** block to reflect that matching is now availability-filtered + AI-ranked, and surface whether a user self-joined and to which group.

---

## 4. Conventions (match the codebase exactly)

- PHP: `if (!defined('ABSPATH')) { exit; }` at top of every file. 4-space indent. Class prefix `TSOL_`. Procedural WP style, no namespaces. Yoda-free, but follow surrounding style. Use `wp_unslash` + `sanitize_*` on all input, `esc_*` on all output, `wp_send_json_success/error`, `check_ajax_referer`, `absint`, `wp_remote_post`, `WP_Error` for failures.
- Hooks/filters: prefix `tsol_site_…`. Provide filters mirroring the existing ones (parent group id, joinable calls, etc.).
- Options: register through the existing settings classes; never write raw `update_option` for user-config without a sanitizer.
- Keep each new class in its own file and add it to the `require_once` list in `tomschooloflife-plugin.php` in dependency order (Gemini client + matcher before the modal class).
- JS: vanilla, IIFE, no new libraries, ES5-compatible to match `accountability-modal.js`. CSS: BEM `tsol-accountability-modal__…`.
- i18n: wrap user-facing strings in `__()/esc_html__()` with text domain `tomschooloflife-plugin`.
- Security: never log or echo the API key; the matcher must only ever act on server-trusted group ids; validate join server-side independent of what the client sends.

---

## 5. Acceptance criteria

1. With a valid Gemini key + group bios set: completing the intake shows a loading state then **3 ranked group cards** with member-facing reasons, constrained to calls the user selected and still open.
2. **Show all groups** button reveals every currently-joinable call grouped by group; when no candidate or weak fit, that state shows automatically with an explanatory message.
3. Clicking **Join** (with `tsol-accountability-groups` active) enrolls the user via the Groups API, which fires `groups_created_user_group` so the engine pushes the Access/RocketChat sync; `user_has_accountability_group()` becomes true; the choice is recorded in user meta; intake is marked complete; the join action hook fires; confirmation shows. Any prior accountability group is removed first (transfer). The modal no longer shows for that user afterward.
4. With the engine **inactive**, the UI never performs a half-join: it shows the "request received, a coach will place you" state, the intake is stored, and no Groups membership is created without sync.
5. Server re-validates join: engine-active, already-member, group-full race, non-accountability group, and logged-out cases all return friendly responses and **never** create a bad/unsynced membership.
6. No API key / AI disabled / Gemini error/timeout → graceful **availability-only** recommendations; joining still works. User is never blocked.
7. No Gemini call is made without availability candidates; results are cached briefly to avoid duplicate billing on re-render.
8. Existing flows (draft save/resume, admin submissions list, CSV export, delete submission, manual placement) still work. Plugin version bumped, `CHANGELOG.md` updated, no PHP notices.

---

## 6. Open questions to raise in the PR (don't block on these — pick the documented assumption)

- ~~Does "join" also require an MEC event RSVP/booking?~~ **Resolved:** itthinx group membership only. `group_full` is a manual staff flag, not booking-driven. Action hook left for future use.
- ~~Is the Access/RocketChat webhook called inline in the engine controller (so a direct `create()` would skip it)?~~ **Resolved:** it is **hook-driven** (`groups_created_user_group` / `groups_deleted_user_group`), so a Groups-API `create()`/`delete()` triggers the sync automatically **provided `tsol-accountability-groups` is active** — hence the soft-dependency gate in §2.
- ~~More than one accountability group allowed?~~ **Resolved:** strictly one — the engine enforces single-group transfer (delete prior, then create). Replicate that; the modal only shows to non-members anyway.
- ~~Self-service join vs. admin approval?~~ **Resolved:** immediate self-service — the AI presents 3 options and the user clicks one to join right away; no approval step. (The engine-absent fallback is the only path that defers to manual placement.)
- Confirm group **bios** should come from itthinx description + facilitator/event title by default vs. requiring staff-written overrides.
- Confirm the Gemini **model** (`gemini-2.5-flash` default) and the structured-output request shape against the live docs at build time.
- **`tsol-accountability-groups` is not in this repo** — confirm Codex can read the deployed version (the export reviewed was v2.0.0 → Access webhook; an older copy may still target n8n). The integration only depends on its hook behavior + `Groups_User_Group`, so version drift is low-risk, but verify `class_exists('AccountabilityGroupsHandler')` is the right gate on the live site.
