# PRD-0 — S5T Launch Sprint (Supersedes scope of PRD v1 for Aug 7–15)

**Mission:** series5technology.com live on the new framework, on a fresh Pressable install, before wheels-up **Sunday Aug 16**. WordCamp Aug 18 — the site is the demo.
**Hard deadline:** end of day **Saturday Aug 15**. No slip. Scope slips instead.
**PRD v1 remains the roadmap.** Nothing here changes the destination; this is the minimum slice that ships.

---

## 1. What v0 is

The agent→WP loop and nothing else. **AI authors code; it runs flawlessly in WP. Every human-editing and QoL feature lives on top of it, later.**

**The contract (definition of "flawless"):** what the agent writes is what WP serves, byte-for-byte, with exactly two sanctioned transforms:
1. **Head assembly** — importer splits each full HTML document: `<head>` contents (title, meta description, OG, canonical, JSON-LD) → stored page meta the theme outputs; `<body>` → fragment, stored verbatim.
2. **Media rewrite** — relative asset paths → attachment-backed markup (srcset, dimensions, lazy, fetchpriority on LCP).

Nothing else touches markup: wpautop, kses, texturize, emoji script, all WP head cruft — disabled/opt-in on the canvas path. Class usage validated against the Foundation stylesheet; unknown class = loud import failure, never silent.

**Acceptance test (= definition of done):** round-trip diff. `wp s5 import ./dist` → curl every page → diff served body vs. source body modulo the two transforms. **Zero unexplained deltas across all nine pages.** This test is permanent — the QA harness seed every future layer must keep green.

## 2. Scope

### In (v0)
- Shell theme: canvas template, Foundation stylesheet enqueue (no Tailwind build — validate against existing CSS), opt-in head, filter kill.
- Plugin: postmeta storage, render pipeline (fragment → `do_shortcode()` → serve), `wp s5 import`, head/body split, class validator, minimal media pass.
- Parts, minimal: header + footer as global HTML fragments. No display conditions.
- SEO gate v0: CLI warnings only — one H1, hierarchy, alt, title/description present, links resolve.
- Nine pages: Home, About, Commerce, Apps, Manage, FAQ, Book a Call, Privacy Policy, **Fix My Store (existing URL preserved exactly)**.
- Launch ops: analytics/scripts hardcoded in theme (panel comes later), favicon, OG images, 404 template, 301 map from any changed old URLs, WP core sitemap + robots, TidyCal embed verified, Pressable page cache on, DNS cutover.

### Out (unchanged from PRD v1, deferred — not descoped)
Editor (all of it), structure tree, components palette + editor screen + manifests, editing levels, meta/entity/scripts panels (agent hand-writes meta + JSON-LD in the HTML — it's our site), schema binding, hide semantics, L&L (no dynamic content on these nine pages), git mirror (repo IS the source; import is the loop), revisions meta, cache purge hooks (manual purge), breadcrumbs, llms.txt, fleet updater, migrations, MCP surface.

## 3. Schedule (real dates)

| Date | Work | Exit |
|---|---|---|
| **Fri 8/7 (today)** | Pressable install provisioned; repo scaffold (`/theme` `/plugin` `/pages` `/parts` `/assets`) + `CLAUDE.md` authoring contract; inventory existing S5T HTML (Claude Design → Astro pages) against the nine-page sitemap; lock slugs + 301 map | Repo exists, contract written, content gaps known |
| **Sat–Sun 8/8–9** | Theme shell + storage + render + filter kill (Day-1–2 work, weekend gets it early) | A hand-placed fragment serves byte-clean |
| **Mon 8/10** | Importer: head/body split, slug mapping, class validator | One real page imports and diffs clean |
| **Tue 8/11** | Media pass (sideload, srcset, dims, lazy, LCP priority); parts (header/footer) | Full page with images + chrome diffs clean |
| **Wed 8/12** | Import all nine pages; round-trip diff green site-wide; SEO gate v0 run; fix content gaps | Whole site renders on staging |
| **Thu 8/13** | Launch ops: scripts, favicon, OG, 404, 301s, sitemap/robots, TidyCal, cache config | Staging is launch-complete |
| **Fri 8/14** | Full review pass (every page, both viewports, CWV spot-check), fix list, then **DNS cutover** | Live on domain |
| **Sat 8/15** | Buffer + post-launch: GSC resubmit, analytics firing, cache purge behavior, redirect spot-checks | Done before the plane |

## 4. Slip protocol (cut in this order, never the deadline)
1. SEO gate v0 → manual checklist pass.
2. Media pass automation → pre-optimized images with hand-written srcset in source HTML.
3. Parts → header/footer temporarily inlined per page (ugly, invisible to visitors, refactor post-trip).
**Uncuttable:** render fidelity (the contract), the importer, all nine pages live, Fix My Store URL preserved, DNS by Fri 8/14.

## 5. Decisions locked
- New WP install on **Pressable** (rides to Rocket.net in November with the fleet).
- **S5T is customer zero** (shawnmorgan.co becomes the editor-phase test bed).
- Source of truth: the repo. Deploy = `wp s5 import` over the existing `@prod` SSH alias pattern.
- Existing S5T HTML from the 2026 rebuild is the content source; agent reworks pages to the full-document authoring convention, not from scratch.

## 6. Post-trip (resumes PRD v1)
Week of Aug 24 = PRD v1 W2 begins (editor technical design doc first, then panel editor), now hardened by a live production site instead of fixtures. WordCamp outcome feeds the name + positioning open questions.
