# Tutor Quiz Resume

**Version:** 2.0.0
**Requires:** WordPress 5.8+, PHP 7.4+, Tutor LMS (free or Pro)
**Compatible with:** Tutor LMS 1.9 → 4.1.1 (last verified), both the **Legacy** quiz UI and the **Modern/Kids** learning mode introduced in Tutor 4.0

Make Tutor LMS quizzes resumable. If a student leaves a quiz, they can come back later — even from a different device — and pick up **where they left off**, with their answers preserved. Leaving the quiz no longer submits it: the attempt stays open and is graded only when the student submits it.

---

## What's new in 2.0 — version independence

Version 1.x was tied to the exact HTML of the classic Tutor quiz. Tutor 4.0 made a new, Alpine.js‑based quiz UI (**Modern** learning mode) the default, with a different form, different ids and its own state handling: on those sites 1.x silently stopped working. 2.0 is designed so that a Tutor update doesn't break it:

| Area | 1.x | 2.0 |
|---|---|---|
| Finding the quiz form | 3 hard‑coded selectors | Known selectors for every Tutor generation **plus** a markup‑independent fallback: the form that contains the answer fields `attempt[ID][quiz_question][QID]` (a naming scheme unchanged from Tutor 1.x to 4.x) |
| Restoring answers | Sets DOM values | Sets values **and** notifies the quiz with `input`/`change` events, so Tutor 4's Alpine form state is updated too (otherwise Tutor would submit empty answers) |
| Jumping to the first unanswered question | Show/hide of `.quiz-attempt-single-question` | Adapter per UI: Tutor 4 → its own `goTo()` navigation; Legacy → show/hide; unknown markup → just scrolls |
| "Leave quiz" must not submit | JS intercept on `#tutor-popup-leave` (Legacy only) | **Server‑side**: the plugin answers Tutor's `tutor_quiz_abandon` AJAX action before Tutor does, so no leave path in any UI can submit the attempt. The JS intercept stays only as a nicety (Legacy → go to the course page) |
| Access to Tutor data | `tutor_utils()->get_attempt()` only | Compatibility layer (`includes/class-tqr-tutor-adapter.php`): Tutor API when available, direct query on the attempts table as fallback |
| Draft cleanup | `tutor_quiz/attempt_ended` + daily cron | Same, plus self‑repair: a draft for a closed attempt is deleted the moment it's requested |
| After a Tutor update | — | Admin notice when Tutor changes version, and an **automatic alert** if a student opens a quiz whose answer form can't be found (likely a markup change) |

### Bugs fixed

- **Fill‑in‑the‑blank with several blanks** (Legacy UI): all blanks share the same field name, so 1.x saved only the last one and wrote it into every blank on restore. Text fields are now keyed by name **and** position.
- A save fired on page exit could reach the server after the final submission and recreate a draft for a finished attempt. Saves are now accepted only for **open** attempts, and saving pauses while the quiz is being submitted.
- Exit saves now use `pagehide` + `visibilitychange` (reliable on mobile) instead of `beforeunload`.
- If the local copy is newer than the server one (e.g. an exit save was lost), it is pushed back to the server so other devices get it.
- The script is loaded as a proper enqueued file, only on quiz pages, instead of being printed inline in every page footer.

Drafts saved by 1.x are still read correctly after the update.

---

## Features

- Saves in‑progress answers while the student types (debounced), in the browser (`localStorage`) and on the server (per‑user meta, scoped to the attempt).
- On return, restores from the browser immediately, then reconciles with the server copy if it's newer.
- Cross‑device resume with the same account.
- Answers are keyed by question ID, so randomized order is fine.
- Each draft belongs to a single attempt: a new attempt always starts empty.
- Takes the student to the first unanswered question — only when there's something to resume; a fresh quiz behaves exactly like stock Tutor.
- Leaving the quiz never submits it.
- AJAX endpoints are nonce‑protected; every read/write checks that the attempt belongs to the logged‑in user.

---

## Installation

1. Download the repository as a ZIP (or the release ZIP).
2. Dashboard → Plugins → Add New → Upload Plugin, choose the ZIP, activate.

**Upgrading from 1.x:** 1.x was a single file. Deactivate and delete the old "Tutor Quiz Resume", then install 2.0 as above. Saved drafts are kept (they live in the database).

---

## Quiz configuration

- **Time limit = 0** (recommended). With a time limit, Tutor auto‑submits or auto‑abandons when the timer expires, even while the student is away. The plugin deliberately does **not** block timer expiry.
- Any **learning mode** (Legacy, Modern, Kids) and any **question layout** work.
- Randomized order and attempts allowed can stay as you like.

**Caching:** exclude quiz pages and `admin-ajax.php` from caching for logged‑in users. A stale cached page carries an expired security token and saving fails.

---

## Supported question types

| Saved & restored | Not saved |
|---|---|
| True/false, single choice, multiple choice, open‑ended, short answer, fill‑in‑the‑blank, image answering | Ordering, matching, image matching (drag & drop) |

Drag‑and‑drop answers are stored by Tutor in hidden fields that are rebuilt by its own scripts; restoring them would mean depending on Tutor's internal drag‑and‑drop code, which is exactly what this version avoids. The student redoes those questions; everything else is restored.

---

## After a Tutor update

1. The plugin shows a notice in the dashboard when Tutor changes version. If the new version is newer than the last verified one (`TQR_Plugin::TESTED_TUTOR`), do a quick test with a student account: answer a couple of questions, leave the quiz, come back.
2. If a student opens an open quiz and the plugin can't find the answer form, you get a red notice with a link to that quiz in **diagnostic mode** (`?tqr_debug=1`): open the browser console to see what was detected.
3. "Leave quiz" protection is server‑side and keeps working even if the front‑end is not recognized.

---

## Customization (filters)

| Filter | Purpose |
|---|---|
| `tqr_selectors` | Array of selectors (`forms`, `modernQuestion`, `legacyQuestion`, `legacyCounter`, `legacyLeave`). Use it if a theme or a future Tutor release renders the quiz differently — no need to edit the plugin. |
| `tqr_block_abandon` | Return `false` to let "leave quiz" submit the attempt as stock Tutor does. |
| `tqr_leave_url` | Destination after "Yes, leave quiz" in the Legacy UI (default: the course page). |
| `tqr_is_quiz_page` | Decide on which pages the script loads. |
| `tqr_open_attempt_statuses` | Attempt statuses considered "open" (default `attempt_started`). |

Action: `tqr_abandon_blocked( $attempt_id )` fires each time a leave‑submission is prevented.

Example:

```php
add_filter( 'tqr_selectors', function ( $s ) {
	$s['forms'][] = 'form.my-theme-quiz-form';
	return $s;
} );
```

---

## Testing checklist

Run it with a **student** account, once per learning mode you use:

1. **Leave without submitting:** answer a few questions, leave (sidebar link, or "Yes, leave quiz"). In Tutor → Quiz Attempts the attempt must still be in progress.
2. **Resume:** reopen the quiz — answers are there and you are on the first unanswered question.
3. **Fill‑in‑the‑blank:** a question with 2+ blanks keeps each blank's own text.
4. **Cross‑device:** open the same quiz from another device — answers appear.
5. **Final submit:** submit the quiz — it's graded and the answers in the results match what was restored.
6. **Clean restart:** start a new attempt — fields are empty.

---

## Troubleshooting

Open the quiz as a logged‑in student with `?tqr_debug=1` at the end of the URL, then the browser console (F12):

- `[TQR] attivo {...}` → the plugin is working; `ui` tells you whether it detected the modern or legacy quiz.
- `[TQR] form del quiz non trovato` → the quiz markup isn't recognized: add the form selector via `tqr_selectors`.
- Network tab → `admin-ajax.php` with `action=tqr_save_draft`: `{"success":true}` is fine; `-1`, `0` or 403 means a stale security token, almost always page caching for logged‑in users.

---

## Structure

```
tutor-quiz-resume.php                 bootstrap
includes/class-tqr-tutor-adapter.php  everything that touches Tutor (compatibility layer)
includes/class-tqr-plugin.php         drafts, leave blocking, cleanup, admin notices
assets/js/tqr-frontend.js             save/restore in the browser
```
