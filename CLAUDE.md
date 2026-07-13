# CLAUDE.md

This file provides guidance for Claude Code when working in this repository.

Project overview and installation live in [README.md](README.md). This file is
Claude-specific navigation + session hygiene, nothing duplicated from there.

## Non-negotiables (failing these loses trust; do not skip)

1. **Tests must pass before every commit.** `vendor/bin/phpunit` — the suite
   boots a real WordPress and needs the test database up
   (`docker compose up -d mysql-test`, MySQL on `127.0.0.1:3311`).
2. **PHPStan must be clean (level 8).** `vendor/bin/phpstan analyse` shows
   `[OK] No errors`. Don't lower the level.
3. **`1.x` is the only long-lived branch.** All work goes there; PRs target
   `1.x`.
4. **Never skip hooks or signing.** No `--no-verify`, no `--no-gpg-sign`.
5. **Destructive ops require explicit user confirmation.** `git push --force`,
   `git reset --hard`, `rm -rf` — call it out and wait.
6. **Don't auto-push after every commit.** Batch locally; push only on
   explicit instruction.
7. **Nothing is written or deleted, ever.** The whole design contract is
   "deactivate and everything comes back": no options, no DB writes, no
   comment deletion. Every feature is add_filter/add_action at runtime.
8. **Non-discussion comment types stay untouched.** Plugins store bookkeeping
   as comments (WooCommerce order notes, action logs). Only the discussion
   types — '' / comment / pingback / trackback, plus the query-var aliases
   'comments', 'pings', 'all' — are silenced (`queriesDiscussion()`).

## Architecture

A single WordPress plugin (`wppack/disable-comments-plugin`, entry point
`wppack-disable-comments.php`) with one class:
`src/DisableCommentsPlugin.php`. `boot()` registers everything in five
groups, each a private method:

- `silenceDiscussion()` — comments_open/pings_open false at `PHP_INT_MAX`;
  empty `comments_array`; zero `get_comments_number` / `wp_count_comments`;
  `comments_pre_query` short-circuits discussion-type queries before the DB
  (`queriesDiscussion()` mirrors WP_Comment_Query's type-clause building —
  keep them in sync if core changes).
- `removePostTypeSupport()` — comments/trackbacks support off every post
  type, late on init.
- `silenceFeedsAndPings()` — comment feeds 404 (template_redirect at 9,
  before redirect_canonical), feed links dropped, pingback surface gone.
- `silenceRestAndXmlRpc()` — /wp/v2/comments routes, post/page 'replies'
  links, pingback./comment XML-RPC methods.
- `cleanAdmin()` — Comments menu, Discussion settings, toolbar bubble,
  Recent Comments widget; direct screen hits redirect to the dashboard.

Conventions: `declare(strict_types=1)`, PER-CS 2.0, one final class, all
hooks registered from static methods, English comments explaining *why*
(each removal states what core does that makes it safe).

## Testing

- The suite boots a real WordPress via wp-phpunit; the plugin is loaded at
  `muplugins_loaded` in `tests/bootstrap.php`, so init-time behavior
  (post-type support, widgets) is exercised for real.
- `tests/TestCase.php` snapshots/restores `$wp_filter` and key globals, and
  wipes posts/comments between tests. wp-phpunit's `WP_UnitTestCase` is not
  used (incompatible with PHPUnit 11).
- New silencing behavior gets a test that inserts real rows
  (`createComment()` writes past the closed gates on purpose) and asserts
  the surface is empty — plus a pass-through test proving non-discussion
  types still work.

## Toolchain Quick Reference

| Task | Command |
|------|---------|
| Databases | `docker compose up -d` (dev on `127.0.0.1:3310` persistent, test on `127.0.0.1:3311` tmpfs) |
| Install deps | `composer install` |
| Run tests | `vendor/bin/phpunit` |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` |
| Code style check | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Code style fix | `vendor/bin/php-cs-fixer fix` |
| Dev server (browser testing) | `bin/dev-server` → http://localhost:8080 (admin / password); override port with `DISABLE_COMMENTS_DEV_PORT` |
| Reset dev site to fresh state | `bin/dev-reset` (also bootstraps first-time setup; seeds a post with a comment, a pingback and an order note) |

Ports are offset from tidy-admin's (3308/3309) so both plugins' databases can
run side by side.

## Browser testing (dev server)

Modeled on tidy-admin/wppack: wp-cli as a dev dependency, `wp-cli.yml`
(`path: web/wp`, `server.docroot: web`), MySQL via `compose.yaml`.
`web/` is gitignored; `bin/dev-reset` is the canonical source of the dev-only
files it generates there (`web/wp-config.php`, `web/index.php`, and the
plugin dev stub — regenerated only when missing).

The plugin stub is a **real file**, never a symlink to the repo root:
`wp_register_plugin_realpath()` would map the plugin dir to the repo root —
an ancestor of `web/` — corrupting `plugin_basename()`/`plugins_url()` for
every plugin under `web/wp-content/plugins/`.

To verify the "reversible" contract in the browser: `bin/dev-reset`, look at
the seeded post (comment visible), activate the plugin, confirm every
surface is gone, deactivate, confirm the comment is back.

## Git commit discipline

- **One commit = one logical change.** Never sweep in unrelated changes;
  stage related files explicitly — no `git add -A`.
- **Conventional Commits**: `<type>(<scope>): <subject>` — feat / fix /
  refactor / style / docs / test / chore / revert; subject imperative,
  ≤ ~70 chars; multi-line bodies via HEREDOC.
- Commit at logical boundaries on your own judgment; never `git push`
  without an explicit instruction.

## Session Hygiene

- **Documentation sync check on every change**: `README.md` and
  `README.ja.md` must stay in sync — update both or neither.
- Edit → test → PHPStan → commit. Never claim done with red tests.
- Discovered a bug along the way? Note it in the commit message or a
  follow-up; don't expand scope silently.
