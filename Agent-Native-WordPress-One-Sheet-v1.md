# Agent-Native WordPress — One Sheet

## The Problem

AI coding agents can now turn a brief, screenshot, or design into a polished Astro, Next.js, or static marketing site in hours.

WordPress should be able to move at the same speed.

But in most WordPress workflows, WordPress itself is not the bottleneck. The bottleneck is the visual-builder abstraction layered on top of it.

An agent that already knows HTML, CSS, JavaScript, components, accessibility, animation, and responsive design is forced to translate its work into a builder-specific representation:

- element trees;
- proprietary JSON;
- block serialization;
- builder APIs;
- drag-and-drop layout models;
- inspector settings;
- proprietary component/state formats.

That translation adds time, brittleness, debugging overhead, and cognitive load — often enough to erase the speed advantage that made agent-driven development attractive in the first place.

For many developers and agencies, this creates an increasingly frustrating choice:

**Build at AI speed outside WordPress, or keep WordPress and accept the builder tax.**

This product exists to remove that tradeoff.

---

## The Thesis

**Agents and developers should author the website directly. WordPress should run it. Humans should edit only what the author intentionally exposes.**

There is no visual authoring abstraction between source and HTML.

A page is HTML.

It can be written by Claude Code, Codex, Cursor, a developer in VS Code, or any future coding agent.

The framework then compiles, validates, stores, and serves that HTML through WordPress while preserving the useful parts of the WordPress ecosystem:

- pages, posts, and CPTs;
- users and roles;
- media library;
- revisions;
- SEO plugins;
- forms and shortcodes;
- hosting and caching;
- plugins and integrations;
- WordPress Abilities and MCP.

The goal is simple:

> **Make building a WordPress marketing site nearly as fast as building the same site in Astro or Next.js.**

---

## What Makes It Different

### 1. No page-builder abstraction

The agent does not learn Bricks, Elementor, Divi, Gutenberg, Oxygen, Etch, or another proprietary page representation.

It writes standards-native frontend source directly.

There is no:

`intent → builder schema → element tree → renderer → HTML`

The path is:

`intent → agent/developer → authoring contract → HTML → compiler → WordPress`

---

### 2. The human editor is a projection, not an authoring environment

Human editability is explicitly granted by the author.

If a heading is wired as editable, the marketer gets a heading field.

If a background image is not wired, there is no background-image control.

If a prop does not exist, the human cannot change it.

**The editor renders declarations, nothing else.**

This is closer to Sanity-style schema thinking than to a traditional visual page builder.

---

### 3. Humans insert registered components, not generic layout primitives

A marketer may be able to add:

- Testimonial;
- CTA;
- FAQ;
- Pricing Card;
- Staff Directory;
- Stat Block.

They do not get:

- Div;
- Container;
- arbitrary Grid/Flex;
- free padding controls;
- free colors;
- arbitrary CSS.

If a capability has not been authored and registered, it does not exist in the human editing surface.

That is intentional.

It prevents clients from accidentally turning a coherent design system into a one-off page-builder mess.

---

### 4. Components are real source, not a hidden canonical tree

Components exist to define reusable structures, props, styling, validation, previews, and migrations.

When inserted, they expand into real HTML inside the page.

The page remains HTML.

The framework never quietly replaces the document with a proprietary component JSON representation.

---

### 5. External agents are first-class; built-in chat is unnecessary

There is no need to ship another AI chat sidebar.

Developers already have Claude Code, Codex, Cursor, and future agent environments.

The framework exposes:

- the Authoring Contract;
- tokens and scales;
- components and manifests;
- page source;
- validation;
- compiler operations;
- WordPress actions;
- quality gates.

External agents connect through files, CLI, WordPress Abilities, and MCP.

Every write path uses the same compiler.

---

## Who It Is For

The initial customer is not a DIY website owner.

It is the WordPress developer, freelancer, or agency that already knows this pain:

> “Claude can build this site in Astro in three hours. Why am I spending another day translating it into a WordPress builder?”

The product is for people who still value WordPress but increasingly dislike the frontend authoring systems layered on top of it.

---

## Core Workflow

### Authoring

`brief / design / screenshots`

↓

`Claude Code / Codex / Cursor / developer`

↓

`HTML + CSS + components + assets`

↓

`compiler / validator`

↓

`WordPress`

### Handoff

`canonical HTML`

↓

`declared editable slots + registered components`

↓

`constrained marketer editing surface`

The author creates capabilities.

The marketer exercises capabilities.

---

## Product Principles

1. **HTML is canonical.**
2. **Agents author directly.**
3. **No builder representation between source and output.**
4. **Compile on write.**
5. **Unknown vocabulary fails loudly.**
6. **Tokens carry values; classes carry decisions.**
7. **The editor renders declarations, nothing else.**
8. **Undeclared content is not editable.**
9. **Unregistered components cannot be inserted.**
10. **CLI, editor, and MCP are three doors into one compiler.**
11. **WordPress supplies the ecosystem; the framework replaces the frontend authoring abstraction.**
12. **Never become a traditional page builder one convenience feature at a time.**

---

## Why Now

The WordPress builder market is rapidly adding AI, agent APIs, Abilities, and MCP support.

That validates the demand for agent-driven WordPress development.

But most incumbent products are still teaching agents to operate the builder abstractions they already own.

This product takes the next architectural step:

> **If the agent already knows how to write the website, remove the builder from its path.**

The opportunity is not to create another AI WordPress builder.

The opportunity is to make WordPress itself feel native to modern agent-driven development.

---

## Positioning

### Category

**Agent-native WordPress frontend**

### Core promise

**Build WordPress sites at code-agent speed.**

### Short explanation

**Your agent writes real HTML and CSS. WordPress runs it. Clients edit only what you expose.**

### Alternative statement

**Agents author. Developers define the rules. Marketers edit declared capabilities. WordPress runs it.**

---

## The Test

The product succeeds if a developer can take the same design and build the WordPress version with roughly the same authoring effort as the Astro/Next/static version — while still handing the client a safe, useful WordPress editing experience.

The north-star metric is:

> **Time from design or brief to production-ready WordPress frontend.**

If the framework removes the builder translation tax, it has solved the problem it exists to solve.
