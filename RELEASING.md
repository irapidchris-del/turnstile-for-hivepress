# Releasing Turnstile for HivePress

The plugin ships with a self-updater (the bundled
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library) that reads this repo's **GitHub Releases**. When you publish a new
release, every site running the plugin sees an update on its **Plugins** screen
— update notice, "View details", and one-click update — with no wp.org listing
required.

## How the pieces fit together

- **Version number** lives in two places in `turnstile-for-hivepress/turnstile-for-hivepress.php`
  (the `Version:` header and the `TFHP_VERSION` constant) and in
  `readme.txt` (`Stable tag:`). Keep them in sync.
- **The release tag** is the version the updater compares against. Tag
  `2.1.0` or `v2.1.0` — the checker strips a leading `v`. An update is offered
  only when the latest release's version is **higher** than the installed one.
- **The release asset** must be named exactly **`turnstile-for-hivepress.zip`**.
  The plugin installs *only* this asset (never GitHub's auto-generated “Source
  code (zip)”, whose folder is named `turnstile-for-hivepress-<tag>` and would
  land in the wrong directory). The fixed asset name is also what powers the
  always-latest download link below.

## Release steps

1. **Bump the version** in all three spots:
   - `turnstile-for-hivepress/turnstile-for-hivepress.php` → `Version:` header
   - same file → `define( 'TFHP_VERSION', 'X.Y.Z' );`
   - `turnstile-for-hivepress/readme.txt` → `Stable tag: X.Y.Z`

   Add a changelog entry to `readme.txt` and `README.md`.

2. **Build the zips:**
   ```bash
   bash bin/build.sh
   ```
   This produces:
   - `dist/turnstile-for-hivepress.zip` — the release asset
   - `dist/turnstile-for-hivepress-X.Y.Z.zip` — an identical, version-named
     copy for your own records / internal testing

   Both contain a top-level `turnstile-for-hivepress/` folder (no version in the
   name), so WordPress installs them into the correct directory.

3. **Commit and tag** on the default branch:
   ```bash
   git commit -am "Release X.Y.Z"
   git tag X.Y.Z
   git push && git push --tags
   ```

4. **Create the GitHub release** for tag `X.Y.Z`, paste the changelog into the
   release notes (these show under "View details" in WordPress), and **attach
   `dist/turnstile-for-hivepress.zip`** as the release asset. Mark it as the
   latest release (GitHub does this by default for the newest non-prerelease).

That's it. Within ~12 hours WordPress sites check for updates automatically;
admins can force an immediate check via **Dashboard → Updates → Check again**.

## The always-latest download link (for the forum post)

Because the asset name is fixed, this URL **always** redirects to the newest
release's asset and downloads it immediately — post it once and never edit it:

```
https://github.com/irapidchris-del/turnstile-for-hivepress/releases/latest/download/turnstile-for-hivepress.zip
```

## Notes

- A **pre-release** on GitHub is ignored by the updater's "latest release"
  logic — handy for testing a build without offering it to everyone.
- If you ever need to test the update flow: install an older version, publish a
  higher-tagged release with the asset, then use **Dashboard → Updates → Check
  again**.
- Updating the bundled library: replace `turnstile-for-hivepress/lib/plugin-update-checker/`
  with a newer release of Plugin Update Checker. The integration uses the
  version-stable `\YahnisElsts\PluginUpdateChecker\v5\PucFactory` alias, so a
  new v5.x minor drops in without code changes.
