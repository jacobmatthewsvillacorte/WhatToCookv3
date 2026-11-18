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
| `wtc-tab-page`, `wtc-direct-page`, `wtc-page--wide` | Put on the page root next to `page`: shared gutter and max width (44 rem, or 62 rem wide). Never set a page's own `max-width` or `padding` |
| `wtc-section` | Vertical block with consistent spacing |
| `wtc-card` | Surface with border, radius, shadow |
| `wtc-eyebrow`, `wtc-eyebrow--brand` | Small uppercase label above a title (brand colour with the modifier) |
| `wtc-title-display` | Large display heading |
| `wtc-stack`, `wtc-inline` | Vertical / horizontal flex with token gap |
| `wtc-form`, `wtc-field-row` | Stack form controls / put two side by side (use instead of `ion-grid`) |
| `wtc-section-heading` | Section title row: heading left, link or count right |
| `wtc-notice`, `--danger`, `--warning` | Inline feedback after an action or failed request |
| `wtc-field` | Form field wrapper |
| `wtc-state`, `wtc-state--loading` / `--empty` / `--error` / `--compact`, `wtc-state__icon`, `wtc-empty-state` | Loading, empty and error blocks. Prefer the `<app-state>` component (below) |
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

Second pass (all verified on screenshots, light and dark, 390 px):

- **One page container.** Every page root now carries `page` plus `wtc-tab-page` or `wtc-direct-page` (meal plan adds `wtc-page--wide`). The per-page `.page { max-width; padding }` rules and the three narrow-phone `.page` padding overrides were deleted, so every screen has the same 20 px gutter. Widths that changed: meal details, plan review, plan setup, meal history and shopping list now use the standard 44 rem; cooking lost its private 46 rem.
- **Shared eyebrow, notice and section heading.** `.eyebrow`, `.message`, `.notice`, `.detail-message` and `.section-heading` were redefined 3 to 14 times each. They are now `wtc-eyebrow--brand`, `wtc-notice` (with `--danger` for errors and `--warning` for cautions) and `wtc-section-heading`; the per-page copies are gone.
- **`<app-state>` component** (`src/app/shared/state/`) replaces every loading, error and empty block (27 across 11 pages). It renders the same markup as `.wtc-state`, so it looks identical. Use it for new screens; project extra buttons (a "Browse recipes" link) as content.
- **Recipe placeholder** (`assets/shapes.svg`) was a loud multicolour shape set. It is now a calm plate-and-cutlery tint of the brand colour that works on light and dark surfaces.
- **Off-grid values** within 1 px of a token (3, 5, 7, 9, 11, 13, 15, 17, 19, 21, 23, 25 px) were rounded to the nearest token. About 150 declarations with px remain, mostly legitimate fixed sizes (widths, heights, icon boxes).

Third pass:

- **Form text is 16 px.** Ionic scales stacked labels by 0.75, so the old 14 px controls gave 10.5 px labels. Controls are now 16 px (labels 12 px), which also stops iOS zooming into a focused field. The rule needs `.hydrated` in the selector because Ionic sets its size on a scoped class that beats a plain element selector.
- **Every state block** (loading, error, empty) now uses `<app-state>`.
- **Remaining off-grid values** are mostly intentional (22/30 px headline sizes, `--padding-start` tweaks on chips, a 116 px bottom inset for the pantry's sticky bar). 16 px radii were rounded to `--wtc-radius-lg`.

Not extracted, deliberately: `plan-review` and `plan-setup` are long (about 450 and 400 lines of TypeScript) but their templates do not repeat blocks, so there is no shared component to pull out. Their size is view logic in the class; split that when you next change behaviour there, not for looks.

Open questions for a designer, not engineers:

- Is the generic plate-and-cutlery placeholder acceptable, or do recipes need real photos?
- Should the 16 px and 28 px radii used by a few cards become tokens?

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
