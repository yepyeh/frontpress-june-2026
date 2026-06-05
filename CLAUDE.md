# FrontPress Studio — project rules

## File size budget

**No source file over 300 lines.** Applies to `.js`, `.jsx`, and `.php` under
`app/src/` and `app/cms/lib/`. Excluded: generated files (`app/admin/assets/`),
vendored code, theme templates.

When a file approaches the limit, split it. Patterns the codebase has already
adopted:

- **React screens** → extract sidebars/panels into their own components and
  pull network plumbing into custom hooks under `src/lib/use*.js`.
  See `screens/PageEditor.jsx` ↔ `components/PageEditorSidebar.jsx`,
  `lib/useToastUiEditor.js`, `lib/usePageMutations.js` for the canonical split.
- **Backend services** → break out orthogonal helpers (`FilesystemUtils`,
  `BackupRestorer`, `ThumbnailGenerator`, `ImageAnnotator`).
- **Pure helpers** → put them in `src/lib/` or `cms/lib/` as a flat module,
  not as a private static method on a class that's already big.

If a split would obscure something cohesive, mention it in the PR description
rather than silently shipping a 400-line file.

## Docs sync

Every new feature, feature change, or removed feature must land with a matching
docs update in the same patch — not "later." The public docs at
https://frontpress.studio/docs are how users discover and use the framework;
shipping code without updating them silently breaks the contract.

The docs site is itself a FrontPress Studio install; pages live as plain
Markdown files under `site/content/docs/*.md` in the marketing-website
checkout. When working on a change here, edit the corresponding doc page
(or add a new one) before considering the work done.

Every release also gets a changelog entry at
`site/content/changelog/<version>.md` in the same checkout — one file per
version, matching the existing frontmatter pattern (`title`, `date`,
`version`, `excerpt`) and using `## Added / ## Changed / ## Fixed / ##
Removed / ## Notes` sections as needed.

The docs-site checkout has no local git repo; changes sit on disk until
the operator pushes them from that install's admin (via the GitHub push
integration). **After updating docs and/or the changelog, explicitly
remind the user to push the files to the server from the docs-site
admin.** Don't assume they'll remember — they have to switch installs
to do it, so the prompt is what gets it shipped.

If the docs site isn't available in the current environment, surface the
required doc edit explicitly in the PR / chat summary so it isn't forgotten.
