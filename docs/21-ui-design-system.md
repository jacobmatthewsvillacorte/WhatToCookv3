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

Fixed after looking at the running app (screenshots at 390 px, light and dark):

- **Dashboard** had no base styles at all. Its stylesheet only overrode classes that were never defined, so the switcher, pantry cards, "use soon" cards and recommendation cards rendered as raw text. It now has a full token-based layout.
- **Meal details:** ingredient status ("Missing", "Review quantity", "Ready") was grey text; it is now a `wtc-status-chip` with the semantic colour.
- **Forms:** added `wtc-form` (stacks controls) and `wtc-field-row` (two controls side by side). The shopping list used `ion-grid`, which indented the quantity/unit row; it now uses `wtc-field-row`. Use these instead of `ion-grid` for forms.
- **Shopping list** rendered an empty white `ion-list` strip when there were no items; it now renders only when items exist.

Not done, on purpose (needs a design decision):

- **One page container.** Pages choose their own max width and padding (`meal-history` 680 px, `meal-details` 760 px, `meal-plan` 980 px, others 44 rem). Side gutters therefore differ by a few pixels from screen to screen (12 to 20 px). `wtc-page` exists; decide a width per page, then switch.
- **Per-page `.eyebrow`, `.message`, `.section-heading`** still differ in colour, weight and spacing. Pick one design each, add it to `global.scss`, delete the page copies. (The dashboard now uses `wtc-eyebrow`.)
- **Stacked form labels are small** (about 10-11 px) because Ionic scales them inside the control. Fixing it needs Ionic's label parts or a different label pattern.
- **Recipe images** fall back to a generic coloured placeholder; a branded placeholder would look calmer.
- **Odd values** with no matching token (13 px, 9 px, 7 px, 16 px/28 px radii), about 200 declarations, 72 in `pantry.page.scss`. Round each to the nearest token when you next edit that screen.
- `plan-review` and `plan-setup` still hold repeated card and day-navigator blocks that should become shared components.

## How to check a UI change

Reading SCSS is not enough: the dashboard shipped unstyled and nobody noticed. Run the app and look, in light and dark mode, at 360-390 px and about 768 px.

1. Backend with a throwaway database (never your real one): create an empty sqlite file, run `DB_DATABASE=<that file> php artisan migrate --seed`, then serve with `DB_DATABASE=<that file>`. Note that `php artisan serve` does not forward environment variables to its worker, so use `php -S` with a tiny router script that calls `putenv('DB_DATABASE=...')` before requiring `public/index.php`.
2. Register a test user, add a few pantry items, generate and save a plan so the screens have content.
3. `npm start`, then screenshot each route (headless Chrome over the DevTools protocol works with no extra dependencies: set `whattocook_token` and `whattocook_user` in `localStorage`, navigate, `Page.captureScreenshot`, toggle `ion-palette-dark` on `body` for dark mode).

## Guided cooking mode (UX spec)

Route `/cooking/:id`. On entry call `GET /meal-plans/{id}/preflight`; if the meal is already completed, redirect to `/meal-details/{id}`. Show recipe name, total time, servings, newline-separated steps, the optional tip, and an ingredient checklist (a preparation aid only; the server decides readiness). Step progress is stored on the device per user and meal and cleared on completion.

Controls: Back / Next with a progress label; a timer of 1-180 minutes (start, pause, reset); up to 5000 characters of notes. Completion:

| Choice | Request | Available when |
| --- | --- | --- |
| Cook and deduct pantry | `POST /meal-plans/{id}/complete` | `can_cook_from_pantry` is true |
| Cooked without pantry update | `POST /meal-plans/{id}/complete-without-deduction` | always, after confirmation |

Both redirect to `/meal-details/{id}`. While a completion request runs, completion and step buttons are disabled. The client never deducts stock or writes history itself.
