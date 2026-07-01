# Tutor Quiz Resume

**Version:** 1.6
**Requires:** WordPress + Tutor LMS (tested with the classic single‑question‑per‑page quiz, feedback mode *Default*)

Make Tutor LMS quizzes resumable. If a student leaves a quiz, they can come back later — even from a different device — and pick up **exactly where they left off**, with their answers preserved, instead of starting over. Leaving the quiz no longer submits it: the attempt stays open and is graded only when the student finishes the last question.

---

## What it does (feature by feature)

### Answer saving & restoring
- Saves in‑progress answers **while the student is typing** (debounced, so it doesn't hammer the server).
- Saves in **two places**: the browser (`localStorage`, for same‑device resume) and the **server** (for cross‑device resume).
- On return, answers are restored **immediately from the browser**, then reconciled with the server if the server copy is newer.
- **Cross‑device:** a student can resume from another device with the **same account** and find their answers.
- Also saves on page exit / tab hide using `navigator.sendBeacon`, so the latest answers aren't lost.

### Resilient restore logic
- Answers are stored **per question ID** (not per position), so they survive **randomized question order**.
- Each draft is tied to a specific **attempt** (`attempt_id`): a brand‑new attempt always starts with empty answer boxes — no leftover "ghost" answers from a previous attempt.
- The server is the source of truth for cross‑device sync, but if the server doesn't respond the plugin keeps working with the local copy (no hard failure).

### Resume position
- On return, the student is taken to the **first unanswered question** in the current order, so nothing gets skipped (robust even with randomized order).
- The plugin only changes navigation **when there is actually something to resume**. A fresh quiz behaves exactly like stock Tutor LMS.

### Question counter
- Keeps the "N / total" counter aligned with the currently visible question (via a `MutationObserver`), correct even with randomized order.

### Leaving the quiz
- Intercepts the **"Yes, leave quiz"** button (`#tutor-popup-leave`): it **blocks Tutor's partial submission**, saves the draft, and sends the student to the course page. The attempt stays **open and resumable**.
- The quiz is actually submitted/graded **only** when the student completes the last question ("Submit Quiz").

### Draft cleanup
- The draft is deleted on **final submission** (Tutor hook `tutor_quiz/attempt_ended`).
- A **daily maintenance task** (WP‑Cron) removes drafts for attempts that are no longer open — i.e. submitted **or deleted by an administrator**.
- The scheduled cron event is removed when the plugin is deactivated.

### Security & correctness
- AJAX endpoints are **nonce‑protected** and restricted to logged‑in users.
- Before reading or writing a draft, the plugin verifies that the attempt actually **belongs to the logged‑in user**.
- Draft content is validated (must be valid JSON, max 200 KB).

---

## Requirements

- WordPress with **Tutor LMS** active.
- Quizzes using **Feedback Mode = Default** with one question per page (the layout this plugin was built for).
- Users must be **logged in** to take quizzes (Tutor's default).

---

## Installation

1. Download `tutor-quiz-resume.php`.
2. Upload it to `wp-content/plugins/` — either the file directly, or inside its own subfolder `wp-content/plugins/tutor-quiz-resume/`.
   *(Alternative: zip the file and use Dashboard → Plugins → Add New → Upload Plugin.)*
3. Go to **Dashboard → Plugins** and activate **"Tutor Quiz Resume"**.

No code changes are needed: the answer selectors are already tuned for the standard Tutor quiz markup (and can be edited at the top of the file if your theme differs).

---

## Quiz configuration

On each quiz where you want this behavior (Quiz → Settings):

- **Time Limit = 0** — the one setting you must check. With a time limit, Tutor auto‑submits when the timer expires (even while the student is away), which defeats the "leave and come back" feature.
- **Feedback Mode = Default** — keeps all questions in a single form that can be captured cleanly.
- **Randomized order** and **Attempts Allowed** can stay as you like; the plugin handles randomized order correctly.

**Caching:** exclude quiz pages (and `admin-ajax.php`) from caching for logged‑in users, and avoid JS minification/optimization on the quiz page. Stale cached pages break the security token used by the save/restore calls.

---

## How it works (short technical overview)

- A small script is added to the quiz page. It reads the attempt ID from the quiz form and uses it as the draft key.
- On every change it snapshots all answer fields (keyed by question ID) and stores them locally + on the server.
- On load it restores the snapshot and jumps to the first unanswered question.
- Server storage uses a per‑user meta entry (`_tutor_quiz_draft_<attempt_id>`), read/written through nonce‑protected AJAX actions (`tqr_get_draft`, `tqr_save_draft`).
- Cleanup happens via the `tutor_quiz/attempt_ended` hook and a daily garbage‑collection cron that drops drafts whose attempt is no longer in the `attempt_started` state.

---

## Testing checklist

1. **Leave without submitting:** answer a few questions, click **"Yes, leave quiz."** You should leave the page **without** the attempt being marked completed (check Tutor → Quiz Attempts). Re‑open the quiz — your answers should be there, on the first unanswered question.
2. **Cross‑device:** open the same quiz on another device with the **same account** — the answers should appear.
3. **Final submit:** reach the last question and click **"Submit Quiz"** — this should submit and grade.
4. **Clean restart:** submit or delete that attempt, then start a new one — the answer boxes should be **empty**.

---

## Known limitation

The submit block acts on the modal's "Yes, leave quiz" button. If the student **closes the browser tab abruptly**, Tutor may still attempt a submission through a separate mechanism that doesn't go through that button. This case is **not** neutralized in this version.

---

## Troubleshooting

If saving/restoring doesn't work, open the browser dev tools (F12) as a **logged‑in student** on the quiz page:

- **Console tab:** look for red errors when the quiz loads or when you type. Errors usually mean another plugin (often a JS minifier/optimizer) is breaking the inline script — disable JS optimization for the quiz page.
- **Network tab:** type an answer and check for a request to `admin-ajax.php` with `action=tqr_save_draft`. Click it and read the response:
  - `{"success":true}` → server side is fine.
  - `-1`, `0`, or a 403 page → the security token is stale, almost always due to **page caching** for logged‑in users. Exclude the quiz page from cache.

**Quick check:** temporarily disable any cache / JS‑minification plugin. That's the number‑one cause of "it suddenly stopped working."

---

## Customization

At the top of the frontend script you can adjust the selectors if your theme renders the quiz differently:

- `FORM_SELECTORS` — the quiz form.
- `QUESTION_SELECTOR` — each single‑question block.
- `COUNTER_SELECTOR` — the "N / total" counter.
- `LEAVE_SELECTOR` — the "leave quiz" confirmation button.

The destination after leaving the quiz is computed by `leaveUrl()` (defaults to the course page); change it there if you prefer another target (dashboard, home, etc.).
