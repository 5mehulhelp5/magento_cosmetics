# олівець (olivets) Theme Visual Redesign — Implementation Plan

**Spec:** `docs/superpowers/specs/2026-09-08-olivets-theme-visual-redesign-design.md` (approved)
**Theme:** `Uho/olivets` (`app/design/frontend/Uho/olivets/`), Hyvä child theme, currently a bare
scaffold with no template overrides.

Each phase is independently verifiable. Do them in order — later phases (header/homepage/PLP/PDP)
depend on the design tokens and fonts landing first. Run Magento/Composer/PHP/npm commands per the
`warden` skill (verified invocations) — the `warden magento`/`warden composer` shorthand in
CLAUDE.md does not work as written. Tailwind rebuilds use the theme's own `npm run build` /
`npm run watch` (see `web/tailwind/package.json`), which itself calls `npx hyva-sources` and
`npx hyva-tokens` before invoking the Tailwind CLI.

---

## Phase 0 — Local preview assignment

The theme isn't assigned to any store view yet (`app/etc/env.php` only defines the `odiag` and
`pr` websites/stores; there is no dedicated olivets site). To QA visually during this plan, use
`local-database-access` to identify the store view codes, then temporarily assign `Uho/olivets` as
the theme for one store view (Admin → Content → Design → Configuration, or
`bin/magento config:set design/theme/theme_id <theme_id> --scope=stores --scope-code=<code>`) —
whichever store view is least disruptive to reuse for local QA (confirm with the user before
touching a live-looking store view rather than guessing).

**Verify:** the assigned store view renders `Uho/olivets` instead of its previous theme when
visited locally.

**Note:** creating a dedicated olivets website/domain (the 3-step process described in the
multi-domain-routing project notes) is out of scope for this plan — this phase is local-QA-only
and should be revisited/reverted once real site provisioning happens.

---

## Phase 1 — Design tokens & self-hosted fonts

**Files:**
- `web/tailwind/hyva.config.json` — `tokens.values.color`: `primary` `#C93A70`, `secondary`
  `#3D6FBF`, `on-primary` `#fff`, `on-secondary` `#fff` (per spec's color table).
- `web/tailwind/tailwind-source.css` — extend the existing `@theme` block with:
  `--color-primary-tint` `#FFE3EE`, `--color-secondary-tint` `#DCEBFF`, `--color-bg` `#FFF8F1`,
  `--color-surface` `#FFFFFF`, `--color-ink` `#4A3F35`, `--color-ink-muted` `#7A6E62`,
  `--color-success` `#2F9E64`, `--color-error` `#D6455E`, `--color-warning` `#E8A23A`,
  `--font-display` (Unbounded stack), `--font-sans` (Nunito stack), plus a shared card-shadow
  utility (`0 4px 14px rgba(74,63,53,0.08)`).
- `web/fonts/unbounded-*.woff2`, `web/fonts/nunito-*.woff2` — downloaded font files for weights
  600/700 (Unbounded) and 400/600/700 (Nunito), Cyrillic + Cyrillic-ext subsets.
- `web/tailwind/tailwind-source.css` (or a new `web/css/fonts.css` imported from it) — `@font-face`
  declarations pointing at the self-hosted files (no Google Fonts CDN reference in production
  output).

**Verify:** `npm run build` inside `web/tailwind/` completes without error; generated
`web/css/styles.css` contains the new custom properties and `@font-face` rules; render a plain
page locally and confirm computed styles pick up `--color-primary` etc. (browser devtools or
`claude-in-chrome`).

---

## Phase 2 — Wordmark partial & Header

**Files:**
- New small template partial for the "олівець" text wordmark (Unbounded 700, ink on light
  surfaces / cream-tint on dark footer) — isolated so it's a one-file swap if a real logo lands
  later.
- Header template override (copied from `vendor/hyva-themes/magento2-default-theme` per Hyvä's
  standard override convention) — wordmark, primary nav (Школа / Офіс / Творчість / Опт), search,
  cart icon with rose (`--color-primary`) count badge. Any new Alpine.js interactivity must be
  CSP-safe (named `x-data` methods, no inline expressions) — use `hyva-csp` skill patterns.

**Verify:** header renders with new styling at the Phase 0 preview store view; `hyva-csp` check
passes on any new/edited PHTML with Alpine expressions.

---

## Phase 3 — Footer

**Files:**
- Footer template override — dark-ink (`--color-ink`) background, cream wordmark, link columns
  including a bulk/business-orders link (per spec's "Опт" business-facing signal).

**Verify:** footer renders correctly at mobile and desktop breakpoints.

---

## Phase 4 — Homepage: hero, category tiles, promo blocks

**Files:**
- Homepage CMS block/widget templates or a homepage-specific layout override — pastel gradient
  hero (`--color-primary-tint` → `--color-secondary-tint`) with headline/CTA, rounded category
  tiles, promo/featured-collection blocks. Hero and promo copy must be CMS-block-driven (not
  hardcoded), so marketers can edit copy without a code deploy.
- CMS block content (via `bin/magento` CLI or Admin) seeded with placeholder Ukrainian copy
  matching the spec's approved mockup (headline, subcopy, CTA label).

**Verify:** homepage renders hero → tiles → promo in the approved visual order; editing the CMS
block content in Admin changes the rendered copy without a template change.

---

## Phase 5 — Category / PLP restyle

**Files:**
- Product-listing item template override — rounded product cards, card-shadow utility, rose "+"
  add-to-cart button, restyled filter/sort controls using the new tokens.

**Verify:** category page grid renders restyled cards; add-to-cart still functions (add a product
from the PLP, confirm mini-cart count updates).

---

## Phase 6 — Product page (PDP) restyle

**Files:**
- Gallery, buy box, and badge (success/warning token) template overrides — buttons restyled to
  token colors/radii.

**Verify:** PDP renders with new styling; add-to-cart and any swatch/option selection still work.

---

## Phase 7 — Cart & mini-cart restyle

**Files:**
- Cart and mini-cart template overrides — card/button language consistent with Phases 5–6.

**Verify:** add/remove/update-qty flows still work in both the mini-cart and full cart page.

---

## Phase 8 — Checkout: tokens only

**No new template overrides expected** — checkout keeps Hyvä's default structure per spec (avoids
conversion risk in the most sensitive step). This phase is a verification pass only.

**Verify:** buttons, links, and focus states on the checkout page visibly inherit the new token
colors/typography (because they come from the same generated `styles.css`) without any checkout
layout/template file having been touched. Complete a full guest checkout end-to-end to confirm
nothing broke.

---

## Phase 9 — Accessibility, cross-page QA, and brand guide doc

**Files:**
- `docs/BRAND_GUIDE_olivets.md` — new file mirroring the structure of the existing
  `docs/BRAND_GUIDE.md` (written for the sibling seed-store theme): palette table with contrast
  ratios, typography table, logo/wordmark usage rules, tone-of-voice notes, localization notes.

**Steps:**
- Run the `accessibility` skill over all new/changed templates from Phases 1–7 (contrast, focus
  states, ARIA on any new interactive components).
- Manual browser QA across home, category, product, cart, and checkout, at desktop and mobile
  breakpoints (`claude-in-chrome` or equivalent).
- Verify Ukrainian Cyrillic rendering (і, ї, є, ґ) in both Unbounded and Nunito at the weights
  actually shipped.

**Verify:** accessibility skill reports no unresolved findings on touched templates; QA pass notes
recorded (or fixed inline) for each of the five page types.

---

## Explicitly out of scope (per spec)

- Custom logo/mascot illustration.
- Checkout structural redesign.
- Dark mode.
- Non-Ukrainian storefront copy/localization.
- Creating a dedicated olivets production website/domain (separate follow-up).
