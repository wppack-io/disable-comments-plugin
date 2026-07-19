# Behavior Specification

What WPPack Disable Comments does while active, group by group, and why each
removal is safe and fully reversible. The design contract behind everything:
**nothing is written or deleted, ever** — every behavior is an
`add_filter`/`add_action` registered at runtime from
`src/DisableCommentsPlugin.php`, so deactivating the plugin restores the site
exactly as it was.

## Discussion (`silenceDiscussion`)

- `comments_open` and `pings_open` return false at `PHP_INT_MAX`, so no theme
  or plugin can reopen discussion.
- Templates receive an empty thread (`comments_array`) and a zero count
  (`get_comments_number`), so themes render neither comment lists nor
  "N Comments" links.
- `comments_pre_query` short-circuits discussion-type queries before they
  reach the database. `queriesDiscussion()` mirrors how `WP_Comment_Query`
  builds its type clauses ('type' and 'type__in' merge into one IN list,
  'type__not_in' becomes NOT IN; `expandTypes()` resolves the query-var
  aliases 'comments', 'pings' and 'all' to the stored DB values) — if core
  changes that clause building, the mirror must follow.
- `wp_count_comments` returns all-zero totals (admin-bar bubble, dashboard
  "At a Glance").

**Deliberately untouched:** comment types other than the discussion set
('' / comment / pingback / trackback). Plugins store bookkeeping as comments
(WooCommerce order notes, action logs); their queries name their own type and
pass through to the database.

## Blocks (`silenceBlocks`)

`COMMENT_BLOCKS` lists every core block that exists only for on-site
discussion:

`core/comments`, `core/comments-title`, `core/comment-template`,
`core/comments-pagination`, `core/comments-pagination-next`,
`core/comments-pagination-numbers`, `core/comments-pagination-previous`,
`core/comment-author-name`, `core/comment-content`, `core/comment-date`,
`core/comment-edit-link`, `core/comment-reply-link`,
`core/post-comments-form`, `core/post-comments-count`,
`core/post-comments-link`, `core/latest-comments`, and the legacy
`core/post-comments`.

Two filters cover the two surfaces:

- **Editor inserter** — `register_block_type_args` sets
  `supports.inserter = false` for each listed block. This works without
  shipping any JavaScript because the editor bootstraps server-side block
  definitions before the JS bundle registers its own, and the block store
  keeps the first definition it sees (`bootstrappedBlockTypes` reducer), so
  the server-side `supports` value wins. The blocks stay *registered*:
  instances already placed in content or templates remain valid — no
  "block unavailable" warnings, no risk of a user save silently dropping
  them — and reappear in the inserter on deactivation.
- **Front end** — `pre_render_block` returns an empty string for the listed
  blocks (respecting an earlier non-null short-circuit), before the render
  callback or any database work runs. Without this, block themes would still
  render an empty Comments wrapper and Latest Comments would print
  "No comments to show." The legacy `core/post-comments` name is no longer
  registered and only matters here, for markup surviving in old content.

**Deliberately untouched:** `core/avatar` — it renders post-author avatars as
well as comment avatars, the block analogue of leaving non-discussion comment
types alone.

## Post type support (`removePostTypeSupport`)

Late on `init` (after every post type has registered), `comments` and
`trackbacks` support is removed from all post types. Core keys the Discussion
metaboxes, the block editor's discussion panel, the comment columns on list
tables and the per-post comment UI off this support, so they all disappear in
one move.

## Feeds and pings (`silenceFeedsAndPings`)

- Comment-feed `<link>` tags are dropped from the document head
  (`feed_links_show_comments_feed`,
  `feed_links_extra_show_post_comments_feed`).
- Requests that still reach a comment feed URL get a plain 404 on
  `template_redirect` at priority 9 — before `redirect_canonical()` at 10,
  so dead feed URLs 404 instead of redirecting.
- The pingback surface is unadvertised: the RSD link is removed and
  `bloginfo('pingback_url')` returns empty. Core itself stops sending the
  `X-Pingback` header and accepting pings once `pings_open` is false.

## REST and XML-RPC (`silenceRestAndXmlRpc`)

- The `/wp/v2/comments` routes are unregistered.
- Post and page REST responses drop their `replies` link (core adds it even
  without comment support; custom post types lose it with their support).
- Every `pingback.*` and comment-related XML-RPC method is unregistered
  (`wp.newComment`, `wp.getComments`, `wp.getCommentCount`, …).

## wp-admin (`cleanAdmin`)

- The Comments menu and Settings › Discussion are removed; direct hits on
  `edit-comments.php`, `comment.php` or `options-discussion.php` redirect to
  the dashboard.
- The toolbar comments bubble is removed everywhere; the classic Recent
  Comments widget is unregistered (its block counterpart is already empty via
  `comments_pre_query`). The dashboard needs nothing extra: "At a Glance"
  hides its comment line at a zero `wp_count_comments`, and the Activity
  widget's recent-comments section queries through `comments_pre_query`.
- The site editor shows a sitewide "Discussion" row on the Home and Index
  templates (the `SiteDiscussion` component) that edits the
  `default_comment_status` option — pointless while every comment surface is
  off, and an option write besides. Core mounts it with no filter, slot or
  support check, so the row is hidden with one inline CSS rule on every block
  editor screen (templates can also be edited from the post editor):

  ```css
  .editor-post-panel__row:has(button[aria-label="<translated label>"]) { display: none; }
  ```

  The toggle's aria-label ("Change discussion settings") is unique to this
  row and is translated through the same core catalog in PHP and JS, so the
  selector follows the admin locale. Fail-open by design: if a future core
  release renames the string, the row merely reappears — nothing breaks.
