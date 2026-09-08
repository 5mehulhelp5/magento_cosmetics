# Brand Guide — олівець

Brand identity for the stationery/office-supplies storefront theme. This is
the source of truth for palette, typography, and wordmark usage — the
`Uho/olivets` Hyvä child theme's Tailwind tokens and templates should derive
from these values, not the other way around.

- **Store name:** олівець ("pencil" — вся канцелярія: school, home, and
  business/bulk office supplies)
- **Market / locale:** Ukraine, `uk_UA`
- **Theme code:** `Uho/olivets` (Hyvä child theme, parent `Hyva/default`)
- **Direction:** "Playful Pastel" — soft pastel gradients, warm cream base,
  fully-rounded pill buttons and rounded-corner cards, soft ink-tinted drop
  shadows. See
  `docs/superpowers/specs/2026-09-08-olivets-theme-visual-redesign-design.md`
  for the full research/rationale behind the direction and typography choice.

## Palette — Playful Pastel

| Token | Hex | Role |
|---|---|---|
| `--color-primary` | `#C93A70` | CTA buttons, links, price emphasis — deep rose |
| `--color-primary-tint` | `#FFE3EE` | Decorative backgrounds (tiles, badges, hero gradient stop) — **ink text only, never white text** |
| `--color-secondary` | `#3D6FBF` | Secondary actions, info accents — deep blue |
| `--color-secondary-tint` | `#DCEBFF` | Decorative backgrounds (tiles, hero gradient stop) — **ink text only, never white text** |
| `--color-bg` | `#FFF8F1` | Page background (warm cream) |
| `--color-surface` | `#FFFFFF` | Cards, header background |
| `--color-ink` | `#4A3F35` | Primary text (warm dark brown, not pure black) |
| `--color-ink-muted` | `#7A6E62` | Secondary/muted text, nav labels |
| `--color-success` | `#2F9E64` | In-stock / confirmation — **non-text indicators only, see note below** |
| `--color-error` | `#D6455E` | Validation errors, out-of-stock — **non-text indicators only, see note below** |
| `--color-warning` | `#E8A23A` | Low-stock badges, notices — **pair with ink text only, see note below** |
| `--color-primary-lighter` | `color-mix(primary 80%, black)` → `#A12E5A` | `.btn-primary` hover background (Hyvä default component token — see contrast note) |
| `--color-primary-darker` | `color-mix(primary 40%, black)` → `#50172D` | `.btn-secondary` text color |

**Rule of thumb:** tint colors (`-tint` suffix) are for backgrounds behind
ink-colored text only; only the deep `primary`/`secondary` tones are
approved for white-text buttons/badges.

All text/background pairs below are checked against WCAG 2.1 AA
(normal text ≥ 4.5:1, large text/non-text UI ≥ 3:1):

| Pair | Ratio | Passes |
|---|---|---|
| White text on Primary button (`#C93A70`) | 4.86 : 1 | AA normal text |
| White text on Secondary button (`#3D6FBF`) | 4.97 : 1 | AA normal text |
| Ink text on Bg (page background) | 9.71 : 1 | AA normal text |
| Ink text on Surface (white cards) | 10.23 : 1 | AA normal text |
| Ink text on Primary-tint | 8.51 : 1 | AA normal text |
| Ink text on Secondary-tint | 8.46 : 1 | AA normal text |
| Ink-muted text on Bg | 4.71 : 1 | AA normal text |
| Ink-muted text on Surface | 4.96 : 1 | AA normal text |
| Primary text/links on Bg | 4.61 : 1 | AA normal text |
| Primary text/links on Surface | 4.86 : 1 | AA normal text |
| Cream wordmark (`text-bg`) on Ink footer | 9.71 : 1 | AA normal text |
| Cream/70 footer copyright on Ink footer | 5.71 : 1 | AA normal text |
| Cream/80 footer nav links on Ink footer | 6.91 : 1 | AA normal text |
| White text on `.btn-primary` hover bg (`--color-primary-lighter`) | 6.88 : 1 | AA normal text |
| `--color-primary-darker` text on Surface (`.btn-secondary`) | 13.99 : 1 | AA normal text |
| Stock-status dot: `--color-success`/`--color-error` against ink/bg | 3.0–4.7 : 1 | AA non-text UI (3:1) — dot is decorative, adjacent text label always present |

### Known contrast constraint: semantic status colors as text/fill

`--color-success`, `--color-error`, and `--color-warning` were defined to
match common "traffic light" semantic conventions, but **none of the three
reach 4.5:1 as either a text-on-solid-fill combination (white or ink text)
or as colored text directly on `--color-bg`/`--color-surface`** — this was
verified with the `accessibility` skill during Phase 9 and is a real,
unresolved limitation of the exact hex values, not a bug in how they're
currently used:

| Token | Ink text on it (fill) | White text on it (fill) | As colored text on Bg/Surface |
|---|---|---|---|
| `--color-success` (`#2F9E64`) | 3.02 : 1 (fails) | 3.39 : 1 (fails) | 3.22–3.39 : 1 (fails) |
| `--color-error` (`#D6455E`) | 2.37 : 1 (fails) | 4.31 : 1 (fails, close) | 4.09–4.31 : 1 (fails, close) |
| `--color-warning` (`#E8A23A`) | 4.70 : 1 (**passes**) | 2.17 : 1 (fails) | 2.07–2.17 : 1 (fails) |

**Current shipped usage is safe**: the only place these tokens are used
today is the PDP stock-status indicator dot
(`Magento_Catalog::product/view/stock-status.phtml`), which is a decorative
non-text `::before` dot — WCAG's non-text contrast floor (3:1) applies, not
the 4.5:1 text floor, and the actual "In stock"/"Out of stock" status is
always conveyed by adjacent real text regardless of color (satisfies SC
1.4.1, "not by color alone"). **Constraint for future work**: do not build a
solid-fill success/error/warning badge with white *or* ink text using these
exact hex values without either (a) restricting to `--color-warning` +
ink text (the one combination that passes), (b) using the token as a
left-border/dot accent next to ink text instead of a fill, or (c) darkening
the token specifically for that component the same way
`--color-primary-lighter`/`-darker` were re-derived below.

### `.btn-primary` / `.btn-secondary` hover-darker deviation

The Hyvä default theme's own `components/button.css` references
`--color-primary-lighter` (hover background for `.btn-primary`) and
`--color-primary-darker` (text color for `.btn-secondary`) but never defines
either anywhere in its own token chain — a pre-existing gap in the parent
theme, not something this theme introduced. Naively deriving
`--color-primary-lighter` by mixing *towards white* (a literal reading of
"lighter") drops white-on-hover-bg contrast to 3.86:1 or lower for every
tested mix percentage, because our primary (`#C93A70`) only clears
white-text contrast by a small margin (4.86:1) to begin with. Both tokens
are instead derived by mixing **towards black** (see
`web/tailwind/tailwind-source.css`), which keeps every pairing that
references them AA-safe. `.btn-secondary`'s rare `:active`/pressed state
(solid primary background + dark text) still couldn't be tuned to pass
4.5:1 through the color tokens alone (best achievable was ~3.3:1), so that
one state is fixed with a small additive CSS rule in our own theme
(`.btn-secondary:is(:active, ...) { color: var(--color-on-primary); }`)
rather than by further distorting the "darker" token — see the comment
block in `tailwind-source.css` for the full reasoning.

**Radii & shadow:** buttons and pills use full rounding (`rounded-full`);
cards, tiles, and inputs use `16px`/`8px` respectively (`rounded-2xl` /
`rounded-lg` in Tailwind's scale); cards get a soft ink-tinted shadow
(`--shadow-card: 0 4px 14px rgba(74, 63, 53, 0.08)`) rather than a hard
default shadow.

## Typography

| Role | Typeface | Weights | Notes |
|---|---|---|---|
| Display / headings | **Unbounded** | 600, 700 | Bold geometric display face, full Cyrillic support |
| Body / UI text | **Nunito** | 400, 600, 700 | Rounded-terminal sans, full Cyrillic support, high legibility at small sizes |

Both typefaces are **self-hosted** — woff2 files (Cyrillic + Cyrillic-ext
subset, which also carries basic Latin/digits in the same file) live in
`app/design/frontend/Uho/olivets/web/fonts/`, loaded via `@font-face` rules
in `web/css/fonts.css` (imported from `tailwind-source.css`). No Google
Fonts CDN reference ships in the generated `styles.css` — avoids a
third-party request for CSP/perf/privacy reasons, matching the self-hosting
pattern already used by the sibling seed-store theme (see
`docs/BRAND_GUIDE.md`).

Glyph coverage for all five shipped font files (Unbounded 600/700, Nunito
400/600/700) was verified programmatically (via `fontTools`) to include the
Ukrainian-specific letters — **і, ї, є, ґ** (lowercase and uppercase) — in
every weight actually shipped, plus basic Latin and digits (needed for
prices, SKUs, and any Latin brand terms). олівець's actual seeded copy
(hero/promo/nav) naturally exercises і, ї, and Є; ґ doesn't appear in the
current copy (it's a rare letter, mostly loanwords) but its glyph presence
was confirmed directly at the font level regardless.

## Logo

**Text wordmark only** for this pass — "олівець" set in Unbounded 700. No
mascot or icon artwork is commissioned yet (see spec's "Out of scope").

The wordmark markup lives in its own partial,
`Magento_Theme/templates/html/header/wordmark.phtml`, and is included
(via plain PHP `include`) from both the header logo slot
(`Magento_Theme/templates/html/header/logo.phtml`) and the footer
(`Magento_Theme/templates/html/footer.phtml`) — a one-file swap if a real
logo/mascot is designed later.

**Usage rules:**
- Two color variants only, controlled by the `$wordmarkVariant` PHP
  variable set before including the partial:
  - `'ink'` (default) — `text-ink` (`#4A3F35`) — light surfaces (header,
    light backgrounds).
  - `'cream'` — `text-bg` (`#FFF8F1`) — the dark-ink footer only.
- Do not hardcode the wordmark markup elsewhere; always include the
  partial so future logo swaps stay a one-file change.
- No stretching, skewing, drop shadows, or recoloring outside the two
  approved variants.

## Icons

Structural UI icons (cart, search, chevrons, filters) use Hyvä's default
Lucide/Heroicons SVG set (unchanged icon set from the Hyvä default theme),
restyled via Tailwind utility classes (rounded pill backgrounds, tinted
hover states, primary/ink coloring) rather than swapped for a different
icon library. Emoji may appear in **editorial/CMS copy** only (e.g., the
homepage hero's "✏️" per the approved mockup) — never as structural/UI
icons in template markup.

## Tone of voice

- Bright and welcoming, but credible to a business buyer placing a bulk
  office order — not a kids-only voice. The primary nav itself (Школа /
  Офіс / Творчість / Опт) is what signals "this is for business too," not a
  duller palette or more formal copy.
- CTAs are short, direct imperatives: *"До каталогу"*, *"Дізнатися про
  опт"* — action, not slogan.
- "Опт" (bulk/wholesale) is a first-class nav item and gets its own footer
  link ("Опт для бізнесу") and homepage promo section — the
  business/bulk-office audience should never feel like an afterthought
  bolted onto a school-supplies site.
- Ukrainian is the only voice for now; no code-switching to Russian or
  English in storefront copy.

## Localization

- `mageplaza/magento-2-ukrainian-language-pack` (already installed
  repo-wide, see `docs/BRAND_GUIDE.md`) covers core Luma/Hyvä-default
  strings.
- All olivets-specific copy shipped in this pass (hero headline/subcopy/CTA,
  promo headline/subcopy/CTA, category tile source names) is
  **CMS-block-driven and/or catalog-data-driven**, not hand-rolled
  translation strings — see the "Homepage: CMS-block-driven copy" section
  below. There is currently no theme `i18n/uk_UA.csv` for olivets because
  no template introduces new *hardcoded* translatable strings; the two
  homepage CMS blocks (`olivets_home_hero`, `olivets_home_promo`) already
  store Ukrainian content directly, same pattern as
  `docs/BRAND_GUIDE.md`'s note that CMS block content doesn't need a CSV
  mapping.
- Should a second locale be added later, the CMS block content (not a CSV)
  is the thing that needs a translated variant per store view.

## Homepage: CMS-block-driven copy

The homepage hero and promo sections are intentionally **not** hardcoded in
templates, so marketers can edit copy from Admin > Content > Blocks without
a code deploy:

| CMS block identifier | Renders in |
|---|---|
| `olivets_home_hero` | `Magento_Theme::home/hero.phtml` (headline, subcopy, CTA) |
| `olivets_home_promo` | `Magento_Theme::home/promo.phtml` (bulk/business-orders callout) |

Both blocks were seeded with placeholder Ukrainian copy during
implementation (via a one-off bootstrap script, since deleted) matching the
approved mockup. The category tiles section
(`Magento_Theme::home/category-tiles.phtml`) is **not** CMS-block-driven —
it reuses the same `Hyva\Theme\ViewModel\Navigation` view model as the
header's desktop menu, so it always reflects the real top-level catalog
category structure rather than a hardcoded/duplicated list.

## Local QA store-view assignment (temporary)

`Uho/olivets` is assigned at the **store-view scope** to the `odiag` store
view (`design/theme/theme_id` config path, scope `stores`, code `odiag`) —
this is the `legal.test` local domain, chosen for local QA over `pr_ua`
(Проросток) and over the theme's own not-yet-domain-mapped `ol_ua` website.
This is explicitly a **local-QA-only convenience**, not a real site
assignment — see the multi-domain-routing project notes for what a real
dedicated olivets production domain would require (3 coordinated changes),
which is out of scope for this redesign pass. Revert the store-view
assignment (or replace it with a proper `ol_ua` domain mapping) once real
site provisioning happens.

## Open for Phase 2+

- No custom logo/mascot yet — wordmark only (see Logo section).
- Checkout keeps Hyvä's default structure — tokens only, no structural
  redesign (deliberate, to avoid conversion risk in the most sensitive
  step).
- Dark mode not built.
- The semantic status-color contrast constraint documented above should be
  revisited the moment a real success/error/warning **badge** (not just the
  existing stock-status dot) is designed — the exact hex values will need a
  component-specific adjustment at that point.
