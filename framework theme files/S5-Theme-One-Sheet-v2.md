# The Agent-Native WordPress Theme — One Sheet (v2)

## The problem

AI coding agents can turn a brief, a Figma file, or a screenshot into a polished Astro, Next.js, or static marketing site in a couple of hours. WordPress should move at that speed too.

In most WordPress workflows, WordPress isn't the bottleneck — the page builder is. An agent that already knows HTML, CSS, and JavaScript is forced to translate its work into a builder's representation: element trees, proprietary JSON, block serialization, builder APIs, inspector settings. That translation adds time, brittleness, and debugging — often enough to erase the speed that made agent-driven development worth it.

So developers and agencies get stuck with a bad choice: **build at AI speed outside WordPress, or keep WordPress and pay the builder tax.**

This theme removes that tradeoff.

---

## The thesis

**Agents author the site. Developers define the rules. Humans edit what the author exposed. WordPress runs it.**

There is no visual builder between source and HTML. A page is HTML — written by Claude Code, Cursor, a developer in a code editor, or any coding agent. The theme compiles, validates, stores, and serves that HTML through WordPress while keeping everything WordPress is good for: pages, posts, CPTs, users and roles, the media library, revisions, SEO plugins, forms, hosting, caching, plugins, Abilities, and MCP.

Page builders were an abstraction built for a pre-agent era, when humans needed to be spared from writing HTML. When the author is an agent already fluent in code, that abstraction is a tax. We remove it.

> **Build a WordPress site nearly as fast as building it in Astro — and hand the client something they can safely edit.**

---

## What makes it different

**HTML is canonical.** The page document is the document. No builder tree sits between what the agent wrote and what WordPress serves. What you approve is what ships — no drift, no "rebuild it in the builder" step.

**Compile on write, not on read.** The theme validates and compiles when you save, then serves fast static HTML. Dynamic pieces run only where you flag them.

**Loud, agent-grade validation.** Every save is checked against a closed design-system vocabulary. Unknown class, inline style, malformed markup — rejected at the door with a diagnostic an agent can fix from, not a silent failure that surfaces later on a live page.

**Two asymmetric surfaces.** Agents and developers *author* — real HTML, components, atoms, rules. Marketers and clients *edit* — only the fields, props, and slots the author intentionally exposed. If it wasn't wired to be editable, it can't be edited. That's not a missing feature; it's the point.

**Disabled means gone.** Turn off a section and it's removed from the served HTML — not hidden with CSS. Crawlers don't see it. No other builder can make that claim, because they compile on read.

---

## The three surfaces

**The page canvas — light-touch, for clients and marketers.** Edit the text and images the author exposed. Swap a component. Nudge a size or spacing step within the design system. No code, no color pickers, no way to break the layout. This is the surface the client touches, and it stays simple on purpose.

**The Component Builder — where new things get made.** Assemble registered atoms (headings, buttons, inputs, icons) into components, decide which props the client can touch and which stay frozen, and save it to the palette. This is where sophistication lives — including wrapping a WordPress block or a plugin's shortcode as a clean, reusable component.

**Theme settings — the control plane.** Your design tokens, type/spacing/radius scales, registered elements, components, templates, and integrations. Fully editable by you and by an agent. The Elements tab doubles as the contract an agent reads to build within your system instead of inventing its own.

---

## Design system, atomic by design

Your tokens and style guide are one source of truth — the same values generate the stylesheet, the living style guide, and the design your agent builds against, so nothing drifts. Registered elements (atoms) compose into components (molecules and organisms). Edit an element once and every use updates. The client only ever touches what you exposed.

---

## It's a real theme

It renders a blog. Single posts, pages, archives, search, 404, comments — natively, from WordPress's own data, no plugin required. Everything a standard block theme does out of the box, this does. Beyond the baseline — custom fields, advanced query loops, WooCommerce — you extend it the way you'd extend any theme, with our native dynamic system arriving to own that end-to-end.

---

## Works with your plugins

**Every injectable is a component.** A form, a slider, a WooCommerce block — each becomes a palette component with its settings exposed as clean props. Shortcode plugins drop in through a paste-once embed or a wrapped component. Modern blocks (including WooCommerce's) inject as hydrating islands: the block's real output, contained, so your catalog pages stay static-fast while the interactive bits work. Your forms plugin, your SEO plugin, your commerce blocks — they just work, because we don't fight WordPress, we ride it.

---

## Built for how work actually happens

- **Git-native.** Your site's source lives in files you can version, branch, and deploy — the workflow every other builder makes impossible.
- **Own the whole thing.** Clone the repo, run two commands, and the site rebuilds on any WordPress. No lock-in to a proprietary format.
- **Fast by default.** Static-cacheable HTML, zero JS on pages that don't need it, images resolved with dimensions and srcset, clean output crawlers and AI answer-engines can actually read.
- **Bring your own agent.** No built-in chatbot. Connect Claude Code, Cursor, or any agent through files, CLI, WordPress Abilities, and MCP. We provide the contract, compiler, and guardrails; you choose the agent.

---

## Who it's for

Agencies and developers building and maintaining real client sites who want AI-speed production without leaving WordPress — and who need to hand clients a site they can update without breaking it. Not a DIY drag-and-drop tool. A professional's theme for a post-page-builder WordPress.

---

*Currently in private testing with agency and ecosystem partners. Founding licenses at launch.*
