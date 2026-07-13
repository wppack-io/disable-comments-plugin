# WPPack Disable Comments

Completely disable comments in WordPress. Activate it and the comment system
is gone; deactivate it and everything comes back. No settings screen, no
options in the database.

## What it does

**Discussion**

- Closes comments and pingbacks on every post type, at a priority no theme or
  plugin can override.
- Existing comments never render: templates receive an empty thread and a
  zero count, so themes show neither comment lists nor "N Comments" links.
- Comment queries for discussion types short-circuit before reaching the
  database — the Latest Comments block, the classic Recent Comments widget
  and direct `WP_Comment_Query` calls all come back empty.

**wp-admin**

- Removes the Comments menu, Settings › Discussion, the Recent Comments
  dashboard widget, the toolbar comments bubble and the classic Recent
  Comments widget. Comment and trackback support is dropped from every post
  type, which also removes the Discussion metaboxes, the block editor's
  discussion panel and the comment columns on list tables.
- Direct hits on `edit-comments.php`, `comment.php` or
  `options-discussion.php` bounce to the dashboard.

**Feeds and machine surfaces**

- Comment feed URLs return 404 and their `<link>` tags disappear from the
  document head.
- The `X-Pingback` header, RSD link and pingback URL are gone; the
  `/wp/v2/comments` REST routes and every comment/pingback XML-RPC method are
  unregistered.

## What it deliberately leaves alone

- **Existing comments stay in the database.** Nothing is deleted or
  rewritten; deactivating the plugin restores them exactly as they were.
- **Non-discussion comment types keep working.** Plugins that store their own
  records as comments (WooCommerce order notes, action logs, …) query them by
  their own type and pass through untouched.

## Requirements

PHP 8.2+, WordPress 6.7+.

## License

MIT
