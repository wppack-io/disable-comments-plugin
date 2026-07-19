<?php

/*
 * This file is part of the WPPack package.
 *
 * (c) Tsuyoshi Tsurushima
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace WPPack\Plugin\DisableCommentsPlugin;

/**
 * Shuts the whole comment system down while the plugin is active. Nothing is
 * written or deleted — existing comments stay in the database untouched and
 * everything comes back on deactivation.
 *
 * Comment types registered by plugins for their own bookkeeping (WooCommerce
 * order notes, action logs, …) are deliberately left alone: only queries for
 * the discussion types (comment, pingback, trackback) are silenced.
 */
final class DisableCommentsPlugin
{
    /** Comment types, as stored in the DB, that make up on-site discussion ('' is the legacy value for 'comment'). */
    private const DISCUSSION_TYPES = ['', 'comment', 'pingback', 'trackback'];

    /**
     * Core blocks whose whole purpose is on-site discussion. core/avatar is
     * deliberately absent — it also renders post-author avatars. The legacy
     * core/post-comments name only matters on the render path: it is no longer
     * registered, but may survive in old template content.
     */
    private const COMMENT_BLOCKS = [
        'core/comments',
        'core/comments-title',
        'core/comment-template',
        'core/comments-pagination',
        'core/comments-pagination-next',
        'core/comments-pagination-numbers',
        'core/comments-pagination-previous',
        'core/comment-author-name',
        'core/comment-content',
        'core/comment-date',
        'core/comment-edit-link',
        'core/comment-reply-link',
        'core/post-comments-form',
        'core/post-comments-count',
        'core/post-comments-link',
        'core/latest-comments',
        'core/post-comments',
    ];

    public static function boot(): void
    {
        self::silenceDiscussion();
        self::silenceBlocks();
        self::removePostTypeSupport();
        self::silenceFeedsAndPings();
        self::silenceRestAndXmlRpc();
        self::cleanAdmin();
    }

    /** No new comments anywhere, and existing ones never render or count. */
    private static function silenceDiscussion(): void
    {
        // Late priority so no theme or plugin can reopen discussion.
        add_filter('comments_open', '__return_false', PHP_INT_MAX);
        add_filter('pings_open', '__return_false', PHP_INT_MAX);

        // Templates receive an empty thread and a zero count, so themes show
        // neither existing comments nor "N Comments" links.
        add_filter('comments_array', '__return_empty_array', PHP_INT_MAX);
        add_filter('get_comments_number', static fn(): int => 0, PHP_INT_MAX);

        // Short-circuit discussion queries at the source: the Latest Comments
        // block, the Recent Comments widget and any direct WP_Comment_Query
        // all come back empty without touching the database. Queries that
        // target only non-discussion types pass through untouched.
        add_filter('comments_pre_query', static function ($comments, \WP_Comment_Query $query) {
            if ($comments !== null || !self::queriesDiscussion($query)) {
                return $comments;
            }

            return $query->query_vars['count'] ? 0 : [];
        }, PHP_INT_MAX, 2);

        // Zero the sitewide totals (admin bar bubble, dashboard "At a Glance").
        add_filter('wp_count_comments', static fn(): object => (object) [
            'approved' => 0,
            'moderated' => 0,
            'awaiting_moderation' => 0,
            'spam' => 0,
            'trash' => 0,
            'post-trashed' => 0,
            'total_comments' => 0,
            'all' => 0,
        ], PHP_INT_MAX);
    }

    private static function queriesDiscussion(\WP_Comment_Query $query): bool
    {
        // Mirror how WP_Comment_Query builds its type clauses: 'type' and
        // 'type__in' merge into one IN list, 'type__not_in' becomes NOT IN.
        $in = self::expandTypes(array_merge(
            (array) $query->query_vars['type'],
            (array) $query->query_vars['type__in'],
        ));
        $notIn = self::expandTypes((array) $query->query_vars['type__not_in']);

        // Discussion is queried if any discussion type survives both clauses
        // (an empty IN list constrains nothing, so every type is queried).
        foreach (self::DISCUSSION_TYPES as $type) {
            if (($in === [] || in_array($type, $in, true)) && !in_array($type, $notIn, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand comment-type query vars to the DB values core matches against:
     * '' and 'all' constrain nothing, 'comment'/'comments' also cover the
     * legacy '' rows, 'pings' covers pingbacks and trackbacks.
     *
     * @param array<mixed> $types
     * @return list<string>
     */
    private static function expandTypes(array $types): array
    {
        $expanded = [];
        foreach ($types as $type) {
            $expanded = array_merge($expanded, match ($type) {
                '', 'all' => [],
                'comment', 'comments' => ['', 'comment'],
                'pings' => ['pingback', 'trackback'],
                default => [(string) $type],
            });
        }

        return $expanded;
    }

    /** Comment blocks disappear from the inserter and produce no output. */
    private static function silenceBlocks(): void
    {
        // Hide from the inserter without unregistering: the editor bootstraps
        // server-side block definitions before the JS bundle registers its
        // own, and the first definition wins, so this supports change reaches
        // the editor with no JS shipped. Already-inserted instances stay
        // valid — no "block unavailable" warnings, everything back on
        // deactivation.
        add_filter('register_block_type_args', static function (array $args, string $name): array {
            if (in_array($name, self::COMMENT_BLOCKS, true)) {
                $args['supports'] = ($args['supports'] ?? []);
                $args['supports']['inserter'] = false;
            }

            return $args;
        }, PHP_INT_MAX, 2);

        // Short-circuit rendering before the block's render callback (and any
        // database work) runs, so blocks already placed in content or block
        // theme templates leave no markup behind.
        add_filter('pre_render_block', static function ($pre, array $block) {
            if ($pre === null && in_array($block['blockName'] ?? null, self::COMMENT_BLOCKS, true)) {
                return '';
            }

            return $pre;
        }, PHP_INT_MAX, 2);
    }

    /**
     * Drop comment and trackback support from every post type: this is what
     * removes the Discussion metaboxes and block-editor panel, the comment
     * column on list tables and the per-post comment UI in one move.
     */
    private static function removePostTypeSupport(): void
    {
        // Late on init, after every post type has registered.
        add_action('init', static function (): void {
            // Keys are the post type names whatever the 'fields' inference says.
            foreach (array_keys(get_post_types()) as $postType) {
                remove_post_type_support($postType, 'comments');
                remove_post_type_support($postType, 'trackbacks');
            }
        }, PHP_INT_MAX);
    }

    /** No comment feeds, no pingback surface. */
    private static function silenceFeedsAndPings(): void
    {
        // Drop the comments-feed <link> tags from the document head.
        add_filter('feed_links_show_comments_feed', '__return_false');
        add_filter('feed_links_extra_show_post_comments_feed', '__return_false');

        // Requests that still reach a comment feed URL get a plain 404.
        add_action('template_redirect', static function (): void {
            if (!is_comment_feed()) {
                return;
            }
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
        }, 9); // Before redirect_canonical() at 10, so dead feed URLs 404 instead of redirecting.

        // Stop advertising the pingback endpoint. pings_open (forced false
        // above) already keeps core from sending the X-Pingback header and
        // from accepting the pings themselves.
        remove_action('wp_head', 'rsd_link');
        add_filter('bloginfo_url', static function ($value, $show) {
            return $show === 'pingback_url' ? '' : $value;
        }, 10, 2);
    }

    /** Close the machine-facing comment surfaces. */
    private static function silenceRestAndXmlRpc(): void
    {
        add_filter('rest_endpoints', static function (array $endpoints): array {
            unset($endpoints['/wp/v2/comments'], $endpoints['/wp/v2/comments/(?P<id>[\\d]+)']);

            return $endpoints;
        });

        // Core adds a 'replies' link to post and page responses even without
        // comment support; drop it so nothing points at the removed route.
        // (Custom post types lose the link with their comment support.)
        foreach (['post', 'page'] as $postType) {
            add_filter("rest_prepare_{$postType}", static function (\WP_REST_Response $response): \WP_REST_Response {
                $response->remove_link('replies');

                return $response;
            });
        }

        add_filter('xmlrpc_methods', static function (array $methods): array {
            foreach (array_keys($methods) as $method) {
                if (str_starts_with($method, 'pingback.') || str_contains($method, 'omment')) {
                    // pingback.*, wp.newComment / wp.editComment / wp.deleteComment,
                    // wp.getComment(s), wp.getCommentCount, wp.getCommentStatusList
                    unset($methods[$method]);
                }
            }

            return $methods;
        });
    }

    /** Take every comment surface out of wp-admin (and off the toolbar everywhere). */
    private static function cleanAdmin(): void
    {
        // Comments menu and the now-pointless Settings > Discussion screen.
        add_action('admin_menu', static function (): void {
            remove_menu_page('edit-comments.php');
            remove_submenu_page('options-general.php', 'options-discussion.php');
        }, PHP_INT_MAX);

        // Direct hits on the removed screens bounce to the dashboard.
        add_action('admin_init', static function (): void {
            global $pagenow;
            if (in_array($pagenow, ['edit-comments.php', 'comment.php', 'options-discussion.php'], true)) {
                wp_safe_redirect(admin_url());
                exit;
            }
        });

        // The dashboard needs nothing extra: "At a Glance" hides its comment
        // line at a zero wp_count_comments, and the Activity widget's recent
        // comments section queries through comments_pre_query and comes back
        // empty. (There has been no separate "Recent Comments" dashboard
        // widget since WP 3.8.)

        // Toolbar comments bubble, in wp-admin and on the front end.
        add_action('admin_bar_menu', static function (\WP_Admin_Bar $bar): void {
            $bar->remove_node('comments');
        }, PHP_INT_MAX);

        // The classic Recent Comments widget (its block counterpart is
        // already empty via comments_pre_query).
        add_action('widgets_init', static function (): void {
            unregister_widget('WP_Widget_Recent_Comments');
        }, PHP_INT_MAX);

        // The site editor's home/index templates show a "Discussion" row that
        // edits the site-wide default_comment_status option — pointless while
        // every comment surface is off. Core mounts it with no filter, slot
        // or support check, so hide it by its toggle's aria-label, the row's
        // only stable hook. PHP and the editor read the same core
        // translations, so the selector follows the admin locale; if core
        // ever renames the string the row merely reappears. Enqueued on every
        // block editor screen because templates can also be edited from the
        // post editor.
        add_action('enqueue_block_editor_assets', static function (): void {
            wp_register_style('wppack-disable-comments', false, [], null);
            wp_enqueue_style('wppack-disable-comments');
            $label = str_replace('"', '\\"', __('Change discussion settings'));
            wp_add_inline_style(
                'wppack-disable-comments',
                '.editor-post-panel__row:has(button[aria-label="' . $label . '"]){display:none;}',
            );
        });
    }
}
