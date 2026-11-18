# UI design system

**Read this on day 6.** Rule of thumb: a page's stylesheet should contain layout that is unique to that page. Colour, radius, spacing, type and common blocks come from tokens and `wtc-*` classes.

## Files

| File | Purpose |
| --- | --- |
| `frontend/src/theme/variables.scss` | Design tokens (`--wtc-*`) for light and dark, mapped onto Ionic's `--ion-*` variables |
| `frontend/src/global.scss` | Shared utility classes (`wtc-*`) |
| `frontend/src/app/<page>/<page>.page.scss` | Page-specific layout only |

## Tokens (in `variables.scss`)

| Group | Names | Notes |
| --- | --- | --- |
| Type | `--wtc-font-size-xs..display`, `--wtc-font-family`, `--wtc-font-family-display`, line-height, letter-spacing | Body 1rem; headings use the display family |
| Space | `--wtc-space-1..16` | 0.25rem steps up to 4rem |
| Page | `--wtc-page-gutter`, `--wtc-content-max` | Side padding and 44rem content width |
| Radius | `--wtc-radius-sm` (10px), `-md` (14px), `-lg` (18px), `-xl` (24px), `-pill` | |
| Shadow | `--wtc-shadow-xs`, `-card`, `-raised`, `--wtc-focus-ring` | |
| Colour | `--wtc-color-canvas`, `surface`, `surface-raised`, `surface-muted`, `text`, `text-muted`, `text-subtle`, `primary`, `primary-strong`, `primary-soft`, `secondary`, `border`, `border-strong`, and `success`, `warning`, `danger`, `info` each with a `-soft` | Dark mode redefines the same names under `body.ion-palette-dark`, so never hard-code a hex |

## Utility classes (in `global.scss`)

| Class | Use |
| --- | --- |
| `wtc-page` | Page container: gutters, max width |
| `wtc-section` | Vertical block with consistent spacing |
| `wtc-card` | Surface with border, radius, shadow |
| `wtc-eyebrow` | Small uppercase label above a title |
| `wtc-title-display` | Large display heading |
| `wtc-stack`, `wtc-inline` | Vertical / horizontal flex with token gap |
| `wtc-field` | Form field wrapper |
| `wtc-state`, `wtc-state--loading` / `--empty` / `--error` / `--compact`, `wtc-state__icon`, `wtc-empty-state` | Loading, empty and error blocks. Use these instead of writing one per page |
| `wtc-status-chip` plus `--success` / `--warning` / `--danger` / `--info` / `--neutral` | Status pills. Use for Ready / Low stock / Missing / Needs review |

## Rules

1. No hex colours, no new `box-shadow` colours, no `px` radii in page SCSS. Use tokens.
2. No `.page`, `.eyebrow`, `.section-heading`, `.message` redefinitions. Those already exist as `wtc-*` (about 70 duplicate selectors across pages were the main source of visual drift).
3. Status colour always carries a text label, never colour alone.
4. Touch targets at least 44 px. Focus rings use `--wtc-focus-ring`.
5. Test every new screen in dark mode (More, Settings, toggle) and at 360 px width.

## Status of the cleanup

Done:

- Every page stylesheet is readable (the seven one-line files were formatted).
- **Colours:** all hard-coded hex values in page styles were replaced by semantic tokens (`text`, `text-muted`, `border`, `success`, `warning`, `danger`, and their `-soft` pairs). This is the one **intentional visual change**: the old pages used a separate green palette (for example the swap-option selection in `meal-details`, pantry status text); they now follow the brand palette and, importantly, now respond to dark mode instead of staying light.
- **Spacing, font size, radius:** values that exactly equal a token (4/8/12/16/20/24/32/40/48/64 px spacing; 12/14/16/18/20/24 px font sizes; 10/14/18/24 px radii) now use `--wtc-space-*`, `--wtc-font-size-*`, `--wtc-radius-*`. These render identically; they just can now be changed in one place.
- Shared shadow and border tokens replace two ad-hoc `rgba(...)` values in `pantry`.

Not done, on purpose (need side-by-side screenshots to decide):

- **One page container.** Pages choose their own max width and padding (`meal-history` 680 px, `meal-details` 760 px, `meal-plan` 980 px, others 44 rem). `wtc-page` already exists; moving each page to it changes layout widths, so decide the width per page, then switch.
- **Per-page `.eyebrow`, `.message`, `.section-heading`** differ in colour, weight and spacing, so they are not duplicates yet. Pick one design for each (for example `wtc-eyebrow` plus a `wtc-notice` class), add it to `global.scss`, then delete the page copies.
- **Odd values** with no matching token (13 px, 9 px, 7 px, 16 px/28 px radii) are untouched. About 200 such declarations remain, 72 of them in `pantry.page.scss`. Round each to the nearest token when you next edit that screen.
- `plan-review` and `plan-setup` still hold repeated card and day-navigator blocks that should become shared components.

How to verify a UI change: run `npm start`, check the screen in light and dark mode at 360 px and 768 px width.

## Guided cooking mode (UX spec)

Route `/cooking/:id`. On entry call `GET /meal-plans/{id}/preflight`; if the meal is already completed, redirect to `/meal-details/{id}`. Show recipe name, total time, servings, newline-separated steps, the optional tip, and an ingredient checklist (a preparation aid only; the server decides readiness). Step progress is stored on the device per user and meal and cleared on completion.

Controls: Back / Next with a progress label; a timer of 1-180 minutes (start, pause, reset); up to 5000 characters of notes. Completion:

| Choice | Request | Available when |
| --- | --- | --- |
| Cook and deduct pantry | `POST /meal-plans/{id}/complete` | `can_cook_from_pantry` is true |
| Cooked without pantry update | `POST /meal-plans/{id}/complete-without-deduction` | always, after confirmation |

Both redirect to `/meal-details/{id}`. While a completion request runs, completion and step buttons are disabled. The client never deducts stock or writes history itself.
