# Agent-Native WordPress Framework

Agents write the site. WordPress runs it. Clients touch only what was marked
touchable.

**The contract:** what the agent writes is what WP serves, byte-for-byte, with
exactly two sanctioned transforms — head assembly and media rewrite. No builder,
no block markup, no compile-on-read.

Agents authoring pages: read `CLAUDE.md`. It is the operational contract.

## Layout

| Path | What |
|---|---|
| `pages/` | One complete HTML document per page. **The source of truth.** |
| `parts/` | Shared chrome fragments (header, footer). |
| `theme/` | Shell theme — canvas template, `styles/`, parts loader. |
| `plugin/` | Storage, render pipeline, importer, class validator. |
| `assets/` | Images, referenced by relative path from `pages/`. |
| `tools/` | Round-trip diff harness, validator, build scripts. |
| `dist/` | **Generated.** What `wp s5 import` consumes. Never hand-edited. |

## Acceptance test

`wp s5 import ./dist` → curl every page → diff served body against source body,
modulo the two transforms. **Zero unexplained deltas.**

This test is permanent. It is the QA harness seed that every future layer must
keep green — build it early and never let it go red.

## Docs

- `CLAUDE.md` — operational authoring rules (agents follow this).
- `Authoring-Contract-v0.1.md` — canonical CSS + declaration spec. Wins on conflict.
- `PRD-0-S5T-Launch-Sprint.md` — v0 scope and launch schedule.
- `PRD-WP-Framework-v1.md` — the full product roadmap.

## Status

v0 — pre-launch. Nothing shipped yet.
