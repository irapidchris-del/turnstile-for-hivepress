# Releasing Turnstile for HivePress

The plugin updates itself from this repository's **GitHub Releases** using
WordPress's native `update_plugins_github.com` filter (WP 5.8+) — no third-party
library. New versions appear on every site's **Plugins** screen (update notice,
"View details", one-click update). See `turnstile-for-hivepress/inc/updater.php`.

A GitHub Actions workflow (`.github/workflows/release.yml`) builds the release
zip and attaches it to the release automatically.

## How the pieces fit together

- **Version number** lives in the main plugin file
  `turnstile-for-hivepress/turnstile-for-hivepress.php` (`Version:` header and
  `TFHP_VERSION`) and in `readme.txt` (`Stable tag:`). Keep them in sync.
- **The `Update URI` header** (`https://github.com/irapidchris-del/turnstile-for-hivepress`)
  is what routes WordPress's update check to our filter. Don't change it.
- **The release tag** is the version the updater compares against. Tag `2.1.0`
  or `v2.1.0` — a leading `v` is stripped. An update is offered only when the
  latest release's version is **higher** than the installed one.
- **The release asset** must be named exactly **`turnstile-for-hivepress.zip`**
  and contain a single top-level `turnstile-for-hivepress/` folder. The updater
  picks the first `*.zip` asset; the fixed name also powers the always-latest
  download link. The workflow builds this for you via `bin/build.sh`.

## Releasing from a Claude / automated session

`gh` and the raw releases REST API are not available inside sessions, so drive
the workflow through the GitHub MCP tools:

1. **Bump the version** in `turnstile-for-hivepress/turnstile-for-hivepress.php`
   (`Version:` + `TFHP_VERSION`) and `readme.txt` (`Stable tag:`). Add a
   changelog entry to `readme.txt` and `README.md`.
2. **Commit and merge to the default branch** (`main`). The workflow must exist
   on `main` for `workflow_dispatch` to be available.
3. **Trigger the workflow** with `actions_run_trigger`:
   - method: `run_workflow`
   - workflow_id: `release.yml`
   - ref: `main`
   - inputs: `{ "tag": "vX.Y.Z", "notes": "<changelog markdown>" }`

   The workflow builds the zip and, since the release doesn't exist yet, runs
   `gh release create "vX.Y.Z" … --target <sha>` with your notes, tagging the
   merge commit and attaching `turnstile-for-hivepress.zip`.
4. **Verify** with `get_release_by_tag` (tag `vX.Y.Z`) that the tag, notes and
   the `turnstile-for-hivepress.zip` asset all landed.

Re-running the workflow for an existing tag force-moves the tag to the new
commit, updates the notes (if provided) and re-uploads the asset with
`--clobber`, so it is safe to run repeatedly.

## Releasing manually (from a normal shell)

1. Bump the version (as above), commit, and push to `main`.
2. `bash bin/build.sh` → produces `dist/turnstile-for-hivepress.zip`.
3. Create a GitHub release for the tag, paste the changelog, and attach
   `dist/turnstile-for-hivepress.zip`. Publishing a `release` event also runs
   the workflow, which re-uploads the asset with `--clobber` as a safety net.

## The always-latest download link (for the forum post)

Because the asset name is fixed, this URL **always** redirects to the newest
release's asset and downloads it immediately — post it once, never edit it:

```
https://github.com/irapidchris-del/turnstile-for-hivepress/releases/latest/download/turnstile-for-hivepress.zip
```

## Notes

- A **pre-release** on GitHub is skipped by the `releases/latest` API, so it
  never triggers an update notice — handy for test builds.
- The updater only activates on installs already running a version that includes
  it (2.1.0+). Distribute 2.1.0 via the zip / forum link once; from then on,
  every newer release auto-updates existing sites.
- To force a check on a site: **Dashboard → Updates → Check again**, or the
  plugin's **Check for updates** row action.
