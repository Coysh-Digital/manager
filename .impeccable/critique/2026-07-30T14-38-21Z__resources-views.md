---
target: the complete authenticated Manager application
total_score: 22
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
timestamp: 2026-07-30T14-38-21Z
slug: resources-views
---
Method: dual-agent (A: design review, isolated · B: detector + measured browser evidence, isolated)

Evidence base: 18 screens rendered from real controllers against `db_test`, seeded with a 12-site fleet, screenshotted at 1440×900 and 390×844 in both themes; all 21 Blade templates read; axe-core WCAG 2.1 AA run on 7 pages × 2 themes; geometry measured at 1440/1080/768/390.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | `site-show` states "Last seen 0 seconds ago", "Last verified: never" and "Last report: never" on one screen. Flash region has no `aria-live`. |
| 2 | Match System / Real World | 3 | Fluent Craft domain language. Backups column headed **Deleted** contains **"4w from now"** - past-tense header over a future date. |
| 3 | User Control and Freedom | 2 | No column sorting anywhere. Add-a-site `<details>` popover has no Escape, no click-outside, no `aria-expanded`. |
| 4 | Consistency and Standards | 2 | Twelve type sizes between 9.5px and 22px; 13/13.5/14px do three different jobs. Deleting a backup uses `window.confirm()`; granting a *read* capability requires typing the site name. |
| 5 | Error Prevention | 3 | Capability grants are best-in-class. Undermined by `confirm()` on the one irreversible action and no undo anywhere. |
| 6 | Recognition Rather Than Recall | 1 | The site page reports none of updates, findings or backups for that site. |
| 7 | Flexibility and Efficiency | 1 | No shortcuts, no sorting, no bulk actions, no saved views, no default "needs attention" view. |
| 8 | Aesthetic and Minimalist Design | 2 | Status triple-encoded per row (group header + 3px stripe + badge). Settings is 11 stacked cards on one ~1,940px scroll. |
| 9 | Error Recovery | 2 | Only `$errors->first()` renders; no field-level errors. Backups shows "No site has permission to back up" above nine stored backups. |
| 10 | Help and Documentation | 4 | Genuinely excellent. Instructions persist *after* they succeed, with a stated reason. |
| **Total** | | **22/40** | **Acceptable - significant improvements needed** |

## Design Specificity Verdict

**The prose is unfakeably this product's. The information architecture is anyone's.**

**LLM assessment.** Nobody could lift this copy into a generic SaaS. Updates explains it does not fetch release notes because "those describe what a version fixes, and holding that against a named unpatched site is a liability rather than a feature." Backups explicitly refuses the end-to-end-encryption claim. The site page routes people to *Utilities → Manager Connector* rather than a shell "because it matters on managed hosting" - written by somebody who has deployed Craft to cPanel.

Strip the strings, though, and what remains is the 2019 Tailwind admin template: fixed 236px sidebar with count pills, sticky topbar with breadcrumb and avatar, a four-tile severity KPI strip, a pink "Actions that cannot be undone" zone, `Filter [text] · [select] · [select] · [Apply]`.

The tell: the fleet table columns are `Site · Environment · Status · Craft · PHP · Connector · Last seen`. Six of seven are inventory attributes. **Not one column answers "what changed since last week"** - the literal job in PRODUCT.md. Swap Craft/PHP/Connector for Plan/Region/Agent and this is a server-monitoring product. The domain knowledge lives in the paragraphs; the skeleton was chosen before anyone asked what a Monday-morning agency dev needs to see.

**Deterministic scan.** Two things to report, one methodological.

*The scan as specified was vacuous.* `detect.mjs` admits only `.html/.css/.scss/.jsx/.tsx/.js/.ts/.vue/.svelte/.astro`. `path.extname('index.blade.php')` is `.php`, so **all 21 templates were skipped and it exited 0**. That is a no-op, not a clean bill of health. Anyone running Impeccable against a Laravel repo should know this.

Re-run against a Blade→HTML mirror and against the 18 rendered pages: **3 findings in source, 25 rendered** - 24 × `side-tab`, 1 × `em-dash-overuse`. The `side-tab` hits are the 3px status rail on the leading `<td>` of table rows, not a card accent; the rule targets cards, so this is a category mismatch rather than a defect. **Zero hardcoded-colour violations** - the Tailwind v4 `@theme inline` token discipline is airtight.

The deterministic layer found essentially nothing, and that is the finding: **every real problem here is structural, and no linter can see structure.**

**Where the detector corrected the review.** Assessment A criticised accessibility broadly. Measurement shows focus visibility is *flawless* - all 15 tab stops on the fleet list carry a uniform `2px solid #C9331C` ring at 2px offset, across links, buttons, `summary`, `input` and `select`. That is a genuine strength, not a gap.

## Overall Impression

This product knows exactly what it believes and has not yet built a screen that acts on it.

The security model is not merely explained, it is *performed* - the capability grant flow teaches the threat model through interaction. That is the hardest thing on this list to do, and it is done.

Then the same product asks a user with 40 client sites to answer "is Northgate Trust OK?" by opening four screens and scanning each by eye for the name.

**The single biggest opportunity:** the fleet list is the landing screen and carries no deltas. Add three columns - updates, findings, backup age - sort by attention, and the arrival question is answered in one view instead of four.

## What's Working

**1. The capability grant flow converts positioning into UI.** Every grant renders `Read-only` and `Cannot modify the website`. The one capability that *is* different looks different - different chips, different colour, an acknowledgement that the backup contains "user accounts, password hashes, sessions and any personal information the site holds", the site name typed out, and a required reason. A user who has used this screen twice understands the threat model without reading documentation.

**2. The badge glyph contract is enforced structurally.** The glyph map lives inside `components/status-badge.blade.php`; there is no code path producing a badge without one. The comment names three reasons - colour vision, greyscale printouts, and the screenshot somebody pastes into a ticket. That third reason proves someone thought about the actual scene of use. Every badge survives desaturation.

**3. "Not reporting updates" refuses to lie by omission.** Nine sites with `updates:read` granted but never reported appear *in the list*, greyed, with "Granted, but has not reported yet" - rather than being excluded so the page can claim "3 sites need updates". Absence of data is treated as data.

## Priority Issues

### [P0] There is no mobile layout, and the app is unusable below ~1100px

`sidebar.blade.php` is `w-[236px] flex-none` with no breakpoint, collapse or drawer. Measured at 390px: **document overflow of +333 to +400px on every authenticated page** (login is the only clean one - it has no sidebar). The sidebar takes 236px, leaving `<main>` 154px, of which 56px is `px-7` padding. Site detail additionally overflows at **768px** (+22px).

**Touch targets: 100% of interactive elements fail 44×44 on every page measured** - 37/37 on the fleet list, 24/24 on findings, 5/5 on login. Nav items are 34.3px tall, theme buttons 28px, the login "remember me" checkbox is 13×13.

**Why it matters.** A shared rota means someone is on call, and the alert this product sends - a security release on three named client sites - arrives on a phone. That link opens a page that cannot be read. This fails WCAG 2.1 AA **1.4.10 Reflow** and **2.5.5 Target Size**, against a stated AA commitment.

**Fix.** Sidebar → `hidden lg:flex` plus a topbar disclosure toggling a `fixed inset-y-0` drawer. Responsive `px-4 lg:px-7`. Below `md`, replace the fleet table with a stacked list: name, domain, status badge, and the one metric that matters. Raise control heights to 44px minimum.

**Suggested command:** `/impeccable adapt`

### [P0] The site page does not report on the site

`sites/show.blade.php` renders Connection, Craft/PHP, Reporting, Queue, Configuration and Recent activity. It renders **zero updates, zero findings, zero backups** for that site. `Capabilities: 3 granted` is a count with no link to which three.

Every question a user brings to a site page requires leaving it and scanning three global lists by eye. Findings link *to* the site; the site does not link *to* its findings. At 40 sites this is unworkable - and the client call is about one client.

Compounding it: the first two things on a healthy site are **"Re-pair this site"** and **"Scheduled tasks - required for this site to report"**, both rendered unconditionally. Every weekly visit opens with a small false alarm.

**Fix.** Three summary cards directly beneath the title: **Updates** (`5.6.2 → 5.6.4` + security badge, or "up to date"), **Findings** (count by severity, linking to `findings?site=`), **Backups** (last success with age, plus an `Overdue` state). Demote re-pairing below the fold and render it only under a disclosure when a connector already exists.

**Suggested command:** `/impeccable layout`

### [P1] Accessibility: one ARIA attribute in 21 templates, and the metadata colour fails AA everywhere

Three measured classes, one stated commitment:

- **Contrast.** `--text-3` is the colour of every table header, every domain name and every timestamp. Measured: **3.69:1** on white, **3.51:1** on `--surface-2`, **3.19:1** on `--pale` - at 9.5–12px, far below any large-text allowance. 39 failing nodes on the fleet list alone; 116 across seven pages in light, 96 in dark. Dark misses by only 0.09–0.32, light by 0.81–1.31. The public marketing site already ships the corrected value.
- **Accessible names.** axe reports **`select-name` (critical)** on both fleet filter selects - no label, no `aria-label`, no `title`. Nine `Delete` buttons on Backups and nine `Request a check` buttons on Updates are identical to a screen reader with no row context. A grep of all 21 templates returns exactly one ARIA attribute: `aria-pressed`, hardcoded `"false"` on all three theme buttons and corrected by JS after paint.
- **Document structure.** Every page contains **exactly one heading - the `h1` - and zero `h2`–`h6`.** Settings carries 4 forms and Capabilities 8 forms with no heading structure between them. No skip link, so every navigation costs 13 tab stops before content. No `aria-live` on the flash region. Group rows in the fleet table are `<td colspan="7">`, so "Needs attention · 3 sites" announces as an ordinary cell.

**Fix.** Adopt the corrected `--text-3`. Add a skip link, real `h2`s per section, `aria-label` on nav/header, `aria-live="polite"` on flashes, names on both selects, `sr-only` site names inside repeated row actions, `aria-expanded` on both `<details>`, and `<th scope="colgroup">` for group rows.

**Suggested command:** `/impeccable audit`

### [P1] Backup health is unmeasurable - the named failure mode produces no signal

PRODUCT.md names the target failure verbatim: *"a nightly backup that has been failing quietly since Tuesday."* The backups screen cannot show that.

It lists artifacts by recency with no per-site last-success rollup, no expected cadence, no overdue state and no failure aggregation. A site whose backups stopped five days ago produces **no signal at all** - its last successful row simply drifts down as healthy sites push newer rows above it. There is no backup *configuration* anywhere: no schedule, no retention control, no definition of "nightly". The retention column is headed `Deleted` and contains future dates.

**Fix.** Make *sites with permission* the primary object of the page, each row carrying last successful backup with age, a `Healthy` / `Overdue` / `Failing` badge with glyph, and consecutive-failure count. Add an expected-cadence field so "overdue" is computable. Rename `Deleted` → `Expires`. Surface "2 sites have no successful backup in 7 days" in the subtitle.

**Suggested command:** `/impeccable shape`

### [P2] Nothing aggregates, so the app degrades exactly at the fleet size it is sold for

The findings model is *rule × site*, but the UI is a flat list of instances: one full-width card each, with an inline "Why not now?" text input. At 12 sites that is four identical cards reading "Development mode is on in production". At 40 sites, one bad deploy template produces 40 cards. No grouping by rule, no filter by site or severity, no bulk acknowledge, no sort. The four severity tiles above are decorative, not filters.

The fleet list has the same problem inverted: twelve rows all reading `Production`, `✓ Connected`, `5.10.8.1`. Six of seven columns are constant. Status is encoded three times per row - group header, 3px stripe, and badge - producing 24 chips of identical text.

**On the missing dashboard:** `/` redirecting to the fleet list is defensible - for a fleet tool, the fleet *should* be home. The gap is that the fleet list was not designed to be a landing screen. The three numbers constituting the weekly answer exist only as small sidebar pills, never shown together with their subjects. The most important element on the page - "0 needing attention" - is 13px `text-text-2` on line two of the subtitle, set smaller and lighter than the button that adds site 41.

**Fix.** Don't add a dashboard route; promote the fleet list into one. Add `Updates`, `Findings` and `Backup` columns, make headers sortable, default-sort by attention, and replace the subtitle with a three-item status line linking to filtered views. Group findings by rule with collapsible headers. A "since last visit" marker would close the job-to-be-done outright.

**Suggested command:** `/impeccable distill`

## Answering your five specific concerns

**Generic SaaS patterns - confirmed, and named.** Sidebar-with-count-pills, topbar-with-avatar, the 4-tile severity KPI strip (which renders four zeros on a fresh install), the pink danger zone, the filter-bar-with-Apply (rendered above zero rows on the empty state).

**Excessive card nesting - not supported by measurement.** Max DOM depth is 12; **zero elements exceed depth 15** on any page; no ancestor chain contains more than one card container. Depth comes from table semantics and flex wrappers. The real defect is different: nesting spent on *nothing*. Each capability card contains a three-column sub-panel (`STATE CHANGED / REASON / ACCESS`) that is two-thirds em-dashes. And site detail nests a `<details>` inside a `<details>` - the only such instance measured.

**Weak hierarchy - confirmed, and it is a type-scale problem.** Twelve sizes between 9.5px and 22px, mostly Tailwind arbitrary values, with 13/13.5/14px doing three different jobs. A 1px difference is not a hierarchy.

**Unclear security communication - the opposite is true.** This is the strongest dimension in the product. The flaw is not clarity but **asymmetric ceremony**: granting a read-only capability costs a checkbox, a typed site name and a typed reason; deleting a backup permanently and revoking a site's connector each cost one click, the former via `window.confirm()`.

**Slowing down an experienced Craft dev - confirmed, five ways.** The four-screen correlation tax; no sortable columns; nine "Request a check" buttons with no bulk action; a filter bar requiring an Apply click with no saved views; and a `Craft 5.10.8.1` column that reports *what version* when the developer needs *how far behind*.

## Persona Red Flags

**Alex - agency dev, weekly rota, 30 client sites.** Opens `sites/{id}`, then Updates, then Findings, then Backups, scanning by eye for the client name in each - nothing links a site to its own data. Cannot sort by "Last seen" to find what went quiet. Nine clicks and nine reloads to request nine checks. Every filter change is select-then-click-then-reload. Scrolls past a re-pairing form on healthy sites thirty times a week. Twelve rows of an identical version number is twelve rows of zero information. No keyboard shortcuts of any kind. **He would keep his spreadsheet.**

**Sam - accessibility-dependent.** 13 tab stops before content on every page load, no skip link. Flash messages announce to nobody. Three theme buttons all report `aria-pressed="false"` and the group has no name - "Light button, Dark button, System button", controlling what? Nine adjacent buttons whose entire accessible name is "Delete", the destructive one behind an OS `confirm()`. Both fleet filter selects are unnamed (axe: critical). Every column header, domain and timestamp at ~3.5:1. Group headings announce as data cells. `account.html` renders "Where you are signed in" as a heading over an empty body - a `@foreach` with no `@empty`.

## Minor Observations

- **Three contradictions the design never anticipated.** "Last seen 0 seconds ago" / "Last verified: never" / "Last report: never" on one screen. "No site has permission to back up" above nine stored backups (capability revoked after backups were taken - both true, irreconcilable to a user). "0 sites, all reporting" beside a green dot on a fresh install.
- **Empty states are chrome around nothing.** `empty-activity` and `activity` are byte-identical in structure. Empty findings shows four zero tiles. Empty sites shows a three-control filter bar. None teaches the pairing flow - the only thing a new user needs.
- **Notifications live five sections down the Settings scroll**, and their warning - "Without one, a security finding waits for somebody to open this interface and notice it" - appears nowhere else. That warning is the product's stated success criterion.
- Settings has no anchors, tabs or in-page nav for 11 sections across ~1,940px.
- Platform health is 18 checks in one undifferentiated grid; the single `Warn` is visually identical to the 17 passes.
- The tone→CSS-variable mapping on the fleet row is a nested ternary inside an inline `style`, duplicating a `match` five lines above. It will drift.
- `Recent activity` shows no count, so "nothing happened" is indistinguishable from "the last five scrolled off".

## Questions to Consider

1. **Why is the ceremony asymmetric?** Granting a read capability costs three deliberate acts; permanently destroying a backup costs one click in an OS dialog. What would this look like if confirmation weight were proportional to what cannot be undone?

2. **If Manager watches and never changes anything, why is every screen shaped like a control panel?** Filter bars, action columns, per-row buttons, a danger zone. The one product in this category with nothing to control looks most like it does. What would a *reading* interface look like - scanned in ninety seconds on a Monday, then closed?

3. **What is the artefact of the Monday session?** The next action is a client call. There is no per-client view, no "since last visit", no export, no printable summary - even though the design deliberately keeps every badge legible in a greyscale printout and a pasted screenshot. It has thought hard about how its output survives being copied out, and provided no way to copy it out.

4. **Is the unit of attention the finding, or the rule?** One bad deploy template produces forty cards.

5. **The copy is the best part of this product and the layout the most generic.** What happens if the layout is derived from the copy rather than the copy poured into a template - if "what changed since last week" is the literal H1 and the structure is built downward from it?
