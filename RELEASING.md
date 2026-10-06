# Releasing

How to cut a new release. **Never move an already-published tag** — Packagist
freezes published versions, so always bump to a new number.

## Pick the version number (SemVer)

Given the current `x.y.z`:

- **Patch** (`x.y.Z`) — bug fixes only, no API change. e.g. `1.0.0 → 1.0.1`
- **Minor** (`x.Y.0`) — new features, backwards-compatible. e.g. `1.0.1 → 1.1.0`
- **Major** (`X.0.0`) — breaking changes. e.g. `1.1.0 → 2.0.0`

## Steps

1. **Make & commit your changes**, and make sure tests pass:
   ```bash
   vendor/bin/pest
   ```

2. **Update `CHANGELOG.md`** — add a new section at the top with dated
   `### Added` / `### Changed` / `### Fixed` bullet points:
   ```markdown
   ## [1.0.1] - YYYY-MM-DD
   ### Fixed
   - Short, specific description of what changed and why.
   ```

3. **Bump the version** in `nativephp.json` (`"version": "1.0.1"`).

4. **Commit** the changelog + version bump:
   ```bash
   git commit -am "Release v1.0.1"
   ```

5. **Tag and push** (annotated tag, matching the number):
   ```bash
   git tag -a v1.0.1 -m "v1.0.1"
   git push origin main
   git push origin v1.0.1
   ```

6. **Create the GitHub release** (notes = the changelog section):
   ```bash
   gh release create v1.0.1 --latest --title "v1.0.1 — <summary>" \
     --notes "**Fixed**
   - …"
   ```

7. **Packagist** auto-updates via the GitHub webhook (usually within seconds).
   Confirm the new version appears on the package's Packagist page.

8. **Consume it** in an app that requires `^1.0`:
   ```bash
   composer update vipertecpro/photo-kit
   ```

## Rules of thumb

- A published version is a permanent contract: `1.0.1` must always be the same
  code for everyone. If you shipped a bad release, **release a fix** (`1.0.2`),
  don't re-tag.
- Keep changelog entries short and specific — one line per change, focused on
  *what changed and why it matters to a user*.
- Native changes are verified on the iOS Simulator and an Android emulator
  before tagging; say so in the changelog entry.
