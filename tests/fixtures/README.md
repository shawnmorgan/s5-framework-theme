# S5 QA corpus (round-trip fidelity)

This directory is the **seed of the permanent QA corpus** for the round-trip
fidelity harness (`tests/acceptance-step3.sh`, run via `npm run test:roundtrip`).

## Where the corpus actually lives

The fixture pages themselves live in **`pages/`** at the repo root, not here.
That is deliberate:

- `pages/` is the source directory the importer reads. `.wp-env.json` maps it
  into the container at `wp-content/s5-pages`, and `wp s5 import` globs
  `pages/*.html`. A real production-bound page "goes in `pages/` like any other."
- The harness imports that same directory and round-trips **every** page in it.
  So adding a page to `pages/` automatically enrolls it in the QA corpus — there
  is no second list to keep in sync.

This `tests/fixtures/` directory holds the corpus **contract and metadata** (this
README, and any harness support files a later step needs), so the QA corpus has a
home of its own even though the page bytes live under `pages/`.

## Current corpus

| File                          | Slug                    | Role                                                    |
| ----------------------------- | ----------------------- | ------------------------------------------------------- |
| `index.html`                  | (front page, `/`)       | Minimal doc; front-page wiring.                         |
| `about.html`                  | `/about/`               | Minimal doc; entity round-trip (`&mdash;`).             |
| `fix-my-store.html`           | `/fix-my-store/`        | Exact-slug preservation; sparse head.                   |
| `series-5-technology.html`    | `/series-5-technology/` | **Real production-bound S5T page.** Nav, hero, `<picture>`, feature grid, CTA, footer; full head with OG + Twitter + JSON-LD Organization. This is the markup-complexity fixture. |

## What the harness guarantees for each page

The body is served **byte-for-byte** identical to the source `<body>` inner HTML.
The only normalizations the harness tolerates are the enumerated **sanctioned
transforms** listed in `tests/acceptance-step3.sh` (head assembly today; media in
Step 6; dynamics in Step 8). Any other difference is an **unexplained delta** and
fails the build with a readable diff.

## Adding a page to the corpus

1. Drop a full HTML document into `pages/` (doctype + `<head>` + `<body>`).
2. Author the `<body>` as the exact bytes you expect served — it is stored and
   returned verbatim, never re-serialized.
3. Run `npm run test:roundtrip`. The new page is round-tripped automatically.

If a page needs a transform that is not yet sanctioned, do **not** loosen the
diff — add the transform as a new numbered entry in the harness's normalizer
(one entry, with a comment), so the sanctioned list stays auditable.
