# Page document changes

All additions are optional; documents written before them validate and render as before.

## 2026-11-05: weekly schedules, sections, configurable headings

### `schedule.weekly` (every block)

```json
"schedule": {"start": null, "end": null,
             "weekly": {"days": [1, 2, 3, 4], "start": "12:30", "end": "14:00"}}
```

- `days`: ISO weekdays, 1 = Monday to 7 = Sunday, 1 to 7 unique entries (stored sorted).
- `start`, `end`: 24-hour `HH:MM` in the **site timezone** (`wp_timezone()`).
- A window belongs to the day it starts on. An `end` at or before `start` runs past midnight
  (`22:00`–`01:00` on Friday covers Friday 22:00 to Saturday 01:00); equal times mean a full day.
- When both are set, the one-off `start`/`end` range and the weekly window must both hold.
- `"weekly": null` is accepted and removes the window.
- DST: times are wall-clock times resolved with `DateTimeImmutable` in the site zone. A start in the
  spring-forward gap moves to the first valid minute after it (02:30 becomes 03:30); an ambiguous
  fall-back time resolves to its first occurrence.
- Evaluated in `src/Render/Schedule.php`; used by the renderer, `/go/`, vCard and subscribe
  (`PageStore::reachable_block()`).

### `group` is now a Section

The type name stays `group`; the editor and AI prompt call it "Section". New props:

| Prop | Values | Default |
|---|---|---|
| `heading` | string, 120 | `title` if set |
| `description` | string, 280 | none |
| `collapsible` | bool | true when the legacy `title` is set, else false |
| `collapsed` | bool (start closed, with `collapsible`) | false |
| `background` | same object as `design.background` (now the shared `$defs/background`) | page background |
| `padding` | `none` `sm` `md` `lg` | `md` with a background, else `none` |
| `radius` | `inherit` `none` `sm` `md` `lg` | `inherit` (the page radius) |
| `text_color` | hex or `""` | page text; with a background and no colour, the page text if it reaches 4.5:1, else black or white |
| `layout` | `list` `grid` | `list` |

Children use the section's text colour (`--cl-text` and `--cl-muted` set on the section).
Hiding or scheduling a section hides all of its children, on the server and in the JS fallback.
Section text against its background is a contrast pair (`Contrast::section_pairs()`, `sectionIssue()`
in the editor) and a non-blocking `low_contrast` warning at `/blocks/{i}/props/text_color`.
Image backgrounds on sections ignore `blur`.

### `heading` props

`size` (`sm` `md` `lg` `xl`, default `md`), `align` (unchanged), `weight` (`normal` `bold` `black`,
default `bold`), `color` (hex or `""`), `uppercase` (bool), `subtext` (string, 160), `divider`
(`none` `line` `dots`, CSS only), `spacing` (`tight` `normal` `loose`, space above), `emoji`
(string, 16) or `icon` (icon slug, as on links; the emoji wins). Classes: `.cl-heading--{size}`,
`--{weight}`, `--upper`, `--{spacing}`, `--div-{line|dots}`.

### Schema plumbing

`design.background` became `{"$ref": "#/$defs/background"}`; `bin/build-ai-schema.php` copies the
new def into `schemas/ai-ops.v1.json`.

## Caching

- A page where any visible block or section has a one-off or weekly schedule that can still change
  defines `DONOTCACHEPAGE` before output (standalone and theme modes, in `template_redirect`; the
  shortcode/block embed mode defines it while rendering) and, for anonymous visitors on pages
  without a password, sends `Cache-Control: public, max-age=N`, where N is the seconds until the
  next schedule boundary, at least 60 and at most 24 hours. Pages without such schedules keep the
  default behaviour (no constant, no header).
- The render cache (transient) still expires at the next boundary of anything on the page
  (schedules, countdowns, NEW flags, latest posts), capped at `Cache::MAX_TTL` (12 hours).
- `/go/` redirects and the vCard download define `DONOTCACHEPAGE` and send
  `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`. Every `crazylinks/v1` REST
  route (subscribe, confirm, unsubscribe included) sends `Cache-Control: no-store` and defines
  `DONOTCACHEPAGE`.
- JS fallback for HTML that a cache still serves: every block or section that was visible at
  render time carries `data-cl-end`, the moment its open window closes (one-off end or the end of
  the current weekly window, following back-to-back windows). `frontend.js` hides it after that.
  It only ever hides: a block outside its window is not in the HTML at all, so nothing can appear
  early; it reappears only when the server renders the page again.
