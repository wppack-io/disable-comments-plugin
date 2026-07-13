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
    /** Comment types that make up on-site discussion ('' is the legacy alias for 'comment'). */
    private const DISCUSSION_TYPES = ['', 'comment', 'pingback', 'trackback'];

    public static function boot(): void
    {
        self::silenceDiscussion();
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

    /** @param \WP_Comment_Query $query */
    private static function queriesDiscussion(\WP_Comment_Query $query): bool
    {
        $types = $query->query_vars['type__in'] ?: $query->query_vars['type'];
        foreach ((array) $types as $type) {
            if (in_array($type, self::DISCUSSION_TYPES, true)) {
                return true;
            }
        }

        // No type constraint means "all types", which includes discussion.
        return (array) $types === [] || (array) $types === [''];
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
            foreach (get_post_types() as $postType) {
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
        }, 9); // Before the feed template callback at 10 renders output.

        // Stop advertising the pingback endpoint; pings_open already refuses
        // the pingbacks themselves.
        add_filter('wp_headers', static function (array $headers): array {
            unset($headers['X-Pingback']);

            return $headers;
        });
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

        // "Recent Comments" dashboard widget.
        add_action('wp_dashboard_setup', static function (): void {
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        });

        // Toolbar comments bubble, in wp-admin and on the front end.
        add_action('admin_bar_menu', static function (\WP_Admin_Bar $bar): void {
            $bar->remove_node('comments');
        }, PHP_INT_MAX);

        // The classic Recent Comments widget (its block counterpart is
        // already empty via comments_pre_query).
        add_action('widgets_init', static function (): void {
            unregister_widget('WP_Widget_Recent_Comments');
        }, PHP_INT_MAX);
    }
}
