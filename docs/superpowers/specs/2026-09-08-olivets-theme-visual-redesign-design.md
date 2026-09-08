# Design: олівець (olivets) Theme Visual Redesign

## Overview

`app/design/frontend/Uho/olivets` is currently a bare Hyvä child theme scaffold
(`theme.xml`, `composer.json`, `registration.php`, default `hyva.config.json`
tokens, no overridden templates). This spec defines a full brand identity and
visual re-skin for it: a stationery/office-supplies storefront ("вся
канцелярія" — school, home, and business/bulk office supplies), not a
kids-only shop.

Goal: a bright, distinctive look that stands out from generic Magento/Hyvä
defaults, while still reading credibly to a business buyer placing a bulk
office order — not just a school-kid consumer.

## Reference inspiration

Researched via web search for stationery/office e-commerce design:

- **Poketo** — bold, single-saturated-color-per-section blocking, high
  contrast, white text on color. (Considered as direction B, not chosen.)
- **Bando** / **Notebook Therapy** — pastel color stories, rounded shapes,
  whimsical-but-clean product presentation, gold/soft accents. This is the
  closest match to the chosen direction.
- Design principle taken from research: a limited palette (one main color +
  one accent) reads as "bright" more successfully than many clashing
  saturated colors — avoided a rainbow/multi-color approach for this reason.

Three directions were mocked up and reviewed with the user via the
brainstorming visual companion: **A — Pencil Box** (cream base + rainbow
stripe motif), **B — Bold Color Block** (Poketo-style saturated blocking),
**C — Pastel Whimsical**. **C was chosen**, then further validated in two
intensity variants (playful vs. toned-down-for-business); the user confirmed
the *original, fully playful* variant (**C1**) should be kept even given the
business/office audience — the nav structure (see Header, below) is what
signals "this is for business too," not a duller palette.

Typography was compared as three Cyrillic-safe pairings (Unbounded+Nunito,
Comfortaa+Nunito, Fredoka+Mulish); **Unbounded + Nunito** was chosen for
being distinctive/confident rather than skewing too young.

A combined header→hero→category-tiles→product-card→footer mockup was
reviewed and approved as the final look-and-feel reference for this spec.

## Brand direction: Playful Pastel

Soft pastel gradients (pink→blue), warm cream base, fully-rounded pill
buttons and rounded-corner cards/tiles, soft ink-tinted drop shadows. Warm
dark-brown "ink" text rather than pure black, to stay warm/friendly instead
of stark.

## Color palette & design tokens

| Token | Hex | Role |
|---|---|---|
| `--color-primary` | `#C93A70` | CTA buttons, links, price emphasis — deep rose, white text passes WCAG AA (4.9:1) |
| `--color-primary-tint` | `#FFE3EE` | Decorative backgrounds (tiles, badges, hero gradient stop) — paired with ink text only, never white text |
| `--color-secondary` | `#3D6FBF` | Secondary actions, info accents — deep blue, white text passes WCAG AA (5.0:1) |
| `--color-secondary-tint` | `#DCEBFF` | Decorative backgrounds (tiles, hero gradient stop) — paired with ink text only |
| `--color-bg` | `#FFF8F1` | Page background (warm cream) |
| `--color-surface` | `#FFFFFF` | Cards, header background |
| `--color-ink` | `#4A3F35` | Primary text (warm dark brown, ~10.2:1 on white/cream) |
| `--color-ink-muted` | `#7A6E62` | Secondary/muted text, nav labels |
| `--color-success` | `#2F9E64` | In-stock / confirmation messaging |
| `--color-error` | `#D6455E` | Validation errors, out-of-stock |
| `--color-warning` | `#E8A23A` | Low-stock badges, notices |

Rule of thumb baked into the tokens: **tint colors (`-tint` suffix) are for
backgrounds behind ink-colored text only; only the deep `primary`/`secondary`
tones are approved for white-text buttons/badges.** This is what keeps the
palette bright without failing contrast — the mistake the initial mockup
swatches would have made if shipped as-is (`#FF8FB1`/`#8FC1FF` white-text
buttons read closer to 3.3:1, below the 4.5:1 AA threshold for normal-size
button text).

Exact hex values above are a starting point verified by hand against WCAG
2.1 AA formulas; final implementation should re-verify with the
`accessibility` skill once real components are built (hover/focus/disabled
states, badge-on-badge combinations, etc. aren't covered by this table).

**Radii & shadow:** buttons and pills use full rounding (`rounded-full`);
cards, tiles, and inputs use `16px`/`8px` respectively; cards get a soft
ink-tinted shadow (`0 4px 14px rgba(74,63,53,0.08)`) rather than a hard
default shadow.

## Typography

| Role | Typeface | Weights | Notes |
|---|---|---|---|
| Display / headings | **Unbounded** | 600, 700 | Bold geometric display face, full Cyrillic support, gives the brand a distinctive silhouette rather than a generic UI font |
| Body / UI text | **Nunito** | 400, 600, 700 | Rounded-terminal sans, full Cyrillic support, high legibility at small sizes |

Fonts should be **self-hosted** (woff2 files under
`app/design/frontend/Uho/olivets/web/fonts/`, loaded via `@font-face` in
`tailwind-source.css`) rather than loaded from the Google Fonts CDN in
production — avoids a third-party request (CSP/perf/privacy, consistent with
this being a Hyvä CSP-conscious theme) and matches the pattern already used
in `docs/BRAND_GUIDE.md` for the sibling seed-store theme, which self-hosts
via pre-downloaded font files referenced from theme assets.

## Logo

**Text wordmark only** for this pass — "олівець" set in Unbounded 700,
ink-colored on light surfaces / cream-tinted on the dark footer. No mascot or
icon artwork is commissioned as part of this spec. The wordmark markup
should be isolated in its own small template partial so it's a one-file swap
if a real logo/mascot gets designed later, rather than scattered inline
markup.

## Icons

Emoji (✏️🖍️🗂️🎨) were used in the visual-companion mockups as fast
placeholders, not a proposal to ship emoji as production UI icons. Structural
UI icons (cart, search, chevrons, category glyphs) should use Hyvä's default
Heroicons SVG set, restyled where relevant (rounded stroke caps, tinted
backgrounds) to match the Playful Pastel language. Emoji may still appear
sparingly in editorial/CMS copy (as in the approved hero mockup's CTA) since
that content is marketer-edited text, not structural markup — but that's a
content choice, not a UI-icon-system choice.

## Page scope (full re-skin)

1. **Design tokens** — `hyva.config.json` (`tokens.values.color`) +
   `tailwind-source.css` `@theme` block: palette above, font tokens, radius
   scale.
2. **Header** — wordmark, primary nav reflecting both audiences (e.g.
   Школа / Офіс / Творчість / Опт — "Опт" = bulk/wholesale for business
   buyers), search, cart icon with rose count badge.
3. **Footer** — dark-ink background, cream wordmark, link columns
   (including a bulk/business-orders link).
4. **Homepage** — pastel gradient hero with headline + CTA, category tiles
   (rounded, tinted backgrounds), promo/featured-collection blocks. Hero and
   promo blocks should be CMS-block-driven so copy is marketer-editable.
5. **Category / PLP** — restyled product-grid cards (rounded corners, soft
   shadow, rose "+" add-to-cart button), filters/sort controls restyled to
   token colors.
6. **Product / PDP** — gallery, buy box, badges (success/warning tokens),
   buttons restyled to token colors/radii.
7. **Cart & mini-cart** — restyled to match card/button language.
8. **Checkout** — **tokens only** (buttons, links, focus states inherit the
   palette/type), no structural layout redesign. Keeping checkout's default
   Hyvä structure is a deliberate choice to avoid introducing conversion
   risk in the most sensitive step; flagging this assumption explicitly for
   spec review in case a branded checkout redesign was actually wanted.

## Technical implementation approach

- Extend `web/tailwind/hyva.config.json` → `tokens.values.color` with the
  primary/secondary/on-primary/on-secondary tokens above (drives Hyvä's
  generated token CSS automatically).
- Extend the `@theme` block in `web/tailwind/tailwind-source.css` with the
  remaining custom tokens (ink, ink-muted, tints, bg, surface, semantic
  colors, font family tokens) — following the existing pattern already
  present in that file (`--color-ink`, `--color-bg`, etc.).
- Add self-hosted `@font-face` declarations for Unbounded/Nunito.
- Override the specific Hyvä default templates needed per the page scope
  above (copied from `vendor/hyva-themes/magento2-default-theme` into the
  theme directory per Hyvä's standard override convention), rather than a
  site-wide CSS-only reskin — required because the "full re-skin" scope
  includes new homepage sections and restructured PLP/PDP card markup, not
  just recolored default markup.
- Any new Alpine.js interactivity (e.g. hero carousel, mobile nav) must
  follow CSP-safe patterns (named `x-data` methods, no inline expressions) —
  use the `hyva-csp` skill/`hyva-helper` agent during implementation.
- After changes: `warden env exec -T php-fpm bin/magento setup:upgrade`,
  `setup:di:compile` (if applicable), `static-content:deploy -f`,
  `cache:flush`.
- As a follow-on deliverable (not blocking this spec), produce
  `docs/BRAND_GUIDE_olivets.md` mirroring the structure of the existing
  `docs/BRAND_GUIDE.md` (written for the sibling seed-store theme) — that
  file is brand-specific despite its generic name, so a same-pattern,
  differently-named file is needed for olivets rather than overwriting it.

## Testing / QA

- Manual browser QA across home, category, product, cart, and checkout
  pages, at both desktop and mobile breakpoints.
- `accessibility` skill pass over new/changed templates (contrast, focus
  states, ARIA on any new interactive components like a hero carousel).
- Verify Ukrainian Cyrillic rendering (і, ї, є, ґ) in both Unbounded and
  Nunito at the weights actually shipped, consistent with how the seed-store
  brand guide verified its own font choices.

## Out of scope

- Custom logo/mascot illustration (wordmark only for now).
- Checkout structural redesign (tokens only).
- Dark mode.
- Non-Ukrainian storefront copy/localization.
