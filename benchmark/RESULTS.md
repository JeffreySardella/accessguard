# AccessGuard benchmark results

Fixtures: 6 pages, each with one planted WCAG violation.

| Fixture | Planted rule | AccessGuard (axe, WCAG 2.2 AA) | pa11y (HTMLCS) errors | Lighthouse a11y |
|---|---|:---:|:---:|:---:|
| Missing image alt | `image-alt` | ✅ caught (1 total) | 1 | n/a |
| Nameless button | `button-name` | ✅ caught (1 total) | 1 | n/a |
| Empty link | `link-name` | ✅ caught (1 total) | 1 | n/a |
| Low contrast text | `color-contrast` | ✅ caught (1 total) | 1 | n/a |
| Untitled iframe | `frame-title` | ✅ caught (1 total) | 1 | n/a |
| Unlabeled input | `label` | ✅ caught (1 total) | 3 | n/a |

**AccessGuard caught 6/6 planted violations by exact rule id.**

Notes: pa11y (HTML_CodeSniffer) is a different engine and reports its own error taxonomy, so its column is an error count, not a per-rule match. Lighthouse reads "n/a" unless `lighthouse` + `chrome-launcher` are installed; its accessibility audit uses a subset of axe-core. All six fixtures target rules that fall *inside* that Lighthouse subset, so this harness demonstrates detection parity on common rules — it does not (and cannot, with these fixtures) demonstrate the full-ruleset coverage advantage, which follows from axe's WCAG 2.2 AA ruleset being a superset. Detection is a commodity across these tools — AccessGuard's differentiator is the governance layer (historical tracking, publish-gating, author attribution, audit export), which none of these single-page tools provide.
