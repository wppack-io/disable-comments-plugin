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

namespace WPPack\Plugin\DisableCommentsPlugin\Tests;

use WP_Admin_Bar;
use WP_Query;
use WP_REST_Request;

final class DisableCommentsPluginTest extends TestCase
{
    public function test_comments_and_pings_are_closed_even_when_a_post_opts_in(): void
    {
        $postId = $this->createPost(['comment_status' => 'open', 'ping_status' => 'open']);

        $this->assertFalse(comments_open($postId));
        $this->assertFalse(pings_open($postId));
    }

    public function test_existing_comments_never_render_or_count(): void
    {
        $postId = $this->createPost();
        $commentId = $this->createComment($postId);

        $this->assertSame([], apply_filters('comments_array', [get_comment($commentId)], $postId));
        $this->assertSame(0, get_comments_number($postId));
    }

    public function test_discussion_queries_short_circuit_to_empty(): void
    {
        $postId = $this->createPost();
        $this->createComment($postId);
        $this->createComment($postId, 'pingback');

        $this->assertSame([], get_comments());
        $this->assertSame([], get_comments(['type' => 'comment']));
        // WP_Comment_Query aliases: 'comments', 'pings' and 'all'.
        $this->assertSame([], get_comments(['type' => 'comments']));
        $this->assertSame([], get_comments(['type' => 'pings']));
        $this->assertSame([], get_comments(['type' => 'all']));
        $this->assertSame(0, get_comments(['count' => true]));
    }

    public function test_non_discussion_comment_types_pass_through(): void
    {
        $postId = $this->createPost();
        $this->createComment($postId);
        $noteId = $this->createComment($postId, 'order_note');

        $notes = get_comments(['type' => 'order_note', 'fields' => 'ids']);
        $this->assertSame([$noteId], array_map('intval', $notes));

        // An exclusion query that rules out every discussion type hits the
        // database too — and sees only the order note.
        $rows = get_comments(['type__not_in' => ['comments', 'pings'], 'fields' => 'ids']);
        $this->assertSame([$noteId], array_map('intval', $rows));
    }

    public function test_sitewide_comment_counts_are_zero(): void
    {
        $postId = $this->createPost();
        $this->createComment($postId);

        $counts = wp_count_comments();
        $this->assertSame(0, $counts->approved);
        $this->assertSame(0, $counts->moderated);
        $this->assertSame(0, $counts->total_comments);
    }

    public function test_post_types_lose_comment_and_trackback_support(): void
    {
        foreach (get_post_types() as $postType) {
            $this->assertFalse(post_type_supports($postType, 'comments'), $postType);
            $this->assertFalse(post_type_supports($postType, 'trackbacks'), $postType);
        }
    }

    public function test_comment_feed_requests_become_404(): void
    {
        $this->createPost();

        global $wp_query, $wp_the_query;
        $wp_query = new WP_Query();
        $wp_the_query = $wp_query;
        $wp_query->query(['feed' => 'comments-rss2', 'withcomments' => 1]);
        $this->assertTrue($wp_query->is_comment_feed);

        // redirect_canonical() would wp_redirect() + exit on the CLI's
        // synthetic request and kill the whole PHPUnit process; drop it for
        // this do_action (the hook snapshot restores it after the test). The
        // plugin's 404 runs at priority 9, before canonical at 10, anyway.
        remove_action('template_redirect', 'redirect_canonical');
        do_action('template_redirect');

        $this->assertTrue($wp_query->is_404());
    }

    public function test_comments_toolbar_node_is_removed(): void
    {
        // The comments bubble only shows for users who can edit_posts; use the
        // install's admin so its absence is down to the plugin, not caps.
        wp_set_current_user(1);
        $_SERVER['HTTP_USER_AGENT'] = '';

        $bar = new WP_Admin_Bar();
        $bar->initialize();
        $bar->add_menus();
        do_action_ref_array('admin_bar_menu', [&$bar]);

        $this->assertNotNull($bar->get_node('new-content'), 'toolbar did not populate');
        $this->assertNull($bar->get_node('comments'));
    }

    public function test_recent_comments_widget_is_unregistered(): void
    {
        global $wp_widget_factory;

        $this->assertArrayNotHasKey('WP_Widget_Recent_Comments', $wp_widget_factory->widgets);
    }

    public function test_comment_rest_routes_are_unregistered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/wp/v2/comments', $routes);
        $this->assertArrayNotHasKey('/wp/v2/comments/(?P<id>[\d]+)', $routes);
    }

    public function test_post_rest_responses_drop_the_replies_link(): void
    {
        $postId = $this->createPost();

        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/wp/v2/posts/' . $postId));

        $this->assertSame(200, $response->get_status());
        $this->assertArrayNotHasKey('replies', $response->get_links());
    }

    public function test_comment_and_pingback_xmlrpc_methods_are_unregistered(): void
    {
        $methods = apply_filters('xmlrpc_methods', [
            'pingback.ping' => 'cb',
            'pingback.extensions.getPingbacks' => 'cb',
            'wp.newComment' => 'cb',
            'wp.getComments' => 'cb',
            'wp.getCommentCount' => 'cb',
            'wp.getPosts' => 'cb',
        ]);

        $this->assertSame(['wp.getPosts' => 'cb'], $methods);
    }

    public function test_comment_feed_links_are_dropped_from_the_head(): void
    {
        $this->assertFalse(apply_filters('feed_links_show_comments_feed', true));
        $this->assertFalse(apply_filters('feed_links_extra_show_post_comments_feed', true));
    }

    public function test_pingback_url_is_empty(): void
    {
        // 'display' is what bloginfo('pingback_url') and core's X-Pingback
        // paths use; the 'raw' default bypasses the bloginfo_url filter.
        $this->assertSame('', get_bloginfo('pingback_url', 'display'));
    }
}
