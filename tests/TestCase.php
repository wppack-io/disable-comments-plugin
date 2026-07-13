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

use PHPUnit\Framework\TestCase as BaseTestCase;
use WP_Hook;

/**
 * Base class that keeps WordPress booted while isolating hooks and globals
 * between tests. wp-phpunit's WP_UnitTestCase is not compatible with
 * PHPUnit 11, so it is not used.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array<string, WP_Hook> */
    private array $filterBackup = [];

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        global $wp_filter;
        $this->filterBackup = array_map(static fn(WP_Hook $hook): WP_Hook => clone $hook, $wp_filter);

        foreach (['wp_query', 'wp_the_query', 'wp_admin_bar'] as $name) {
            $this->globalsBackup[$name] = $GLOBALS[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        global $wp_filter, $wpdb;
        $wp_filter = $this->filterBackup;

        foreach ($this->globalsBackup as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        wp_set_current_user(0);

        // Tests insert posts and comments straight into the DB; wipe them (and
        // their caches) so counts in later tests start from zero.
        $wpdb->query("DELETE FROM {$wpdb->comments}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        wp_cache_flush();
    }

    protected function createPost(array $args = []): int
    {
        $postId = wp_insert_post($args + [
            'post_title' => 'Discussion test post',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'open',
        ]);
        \assert(is_int($postId) && $postId > 0);

        return $postId;
    }

    /** Insert straight into the DB, past every "comments are closed" gate. */
    protected function createComment(int $postId, string $type = 'comment'): int
    {
        $commentId = wp_insert_comment([
            'comment_post_ID' => $postId,
            'comment_content' => 'A comment',
            'comment_author' => 'alice',
            'comment_author_email' => 'alice@example.test',
            'comment_type' => $type,
            'comment_approved' => 1,
        ]);
        \assert(is_int($commentId) && $commentId > 0);

        return $commentId;
    }
}
