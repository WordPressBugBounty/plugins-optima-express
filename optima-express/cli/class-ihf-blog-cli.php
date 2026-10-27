<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WP-CLI: read-only view of the blog credential migration (MAX-4396 Step 12).
 *
 *   wp ihf blog-migration status [--format=<table|json>]
 *   wp ihf blog-migration list --state=<pending|disconnected|squatted|demote-skipped|shared-holder> [--format=<table|json|csv>]
 *
 * Loaded and registered only when defined('WP_CLI') && WP_CLI (see iHomefinder.php). Emits the
 * same redacted data as the network admin page: counts, blog ids, home URLs, states and times.
 */
class iHomefinderBlogCli
{

    /**
     * Registers the two subcommands as explicit callables, so no method name has to double as
     * a command name (list is a PHP reserved word).
     *
     * @return void
     */
    public static function register()
    {
        $cli = new self();
        WP_CLI::add_command('ihf blog-migration status', array($cli, 'status'));
        WP_CLI::add_command('ihf blog-migration list', array($cli, 'listBlogs'));
    }

    /**
     * Migration counts for the network (single site: this blog) plus the shared user state.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ihf blog-migration status
     *     wp ihf blog-migration status --format=json
     *
     * @param array $args
     * @param array $assocArgs
     * @return void
     */
    public function status($args, $assocArgs)
    {
        $report = iHomefinderAdminMigrationStatus::getInstance()->buildReport();
        $data = array(
            'generated_at' => $report['generated_at'],
            'multisite' => $report['multisite'] ? 1 : 0,
            'sites_scanned' => $report['sites_scanned'],
            'sites_failed' => $report['sites_failed'],
        );
        foreach ($report['counts'] as $key => $value) {
            $data[$key] = (int) $value;
        }
        $shared = $report['shared'];
        $data['migration_paused'] = $report['controls']['paused'] ? 1 : 0;
        $data['migration_disabled_by_constant'] = $report['controls']['disabled_by_constant'] ? 1 : 0;
        $data['shared_user_id'] = $shared['user_id'];
        $data['shared_live_count'] = $shared['live_count'];
        $data['shared_live_count_at'] = $shared['live_count_at'];
        $data['shared_zero_since'] = $shared['zero_since'];
        $data['shared_purge_scheduled_at'] = $shared['purge_scheduled_at'];
        $data['shared_purge_blocked_by'] = $shared['blocking_reason'];
        $data['shared_password_count'] = $shared['password_count'];
        $data['shared_foreign_password_count'] = $shared['foreign_password_count'];
        $data['shared_retire_armed'] = $shared['armed'] ? 1 : 0;
        $data['shared_retire_hold'] = $shared['hold'] ? 1 : 0;
        $data['shared_retired_at'] = $shared['retired_at'];
        $data['shared_retired_count'] = $shared['retired_count'];
        $data['shared_retired_by'] = $shared['retired_by'];

        $format = isset($assocArgs['format']) ? (string) $assocArgs['format'] : 'table';
        if ($format === 'json') {
            WP_CLI::print_value($data, array('format' => 'json'));
            return;
        }
        $rows = array();
        foreach ($data as $field => $value) {
            $rows[] = array('field' => $field, 'value' => $value === null ? '' : (is_bool($value) ? (int) $value : $value));
        }
        \WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
    }

    /**
     * Blogs in one migration state: blog id and home URL (and the reason for shared-holder).
     *
     * ## OPTIONS
     *
     * --state=<state>
     * : Which list.
     * ---
     * options:
     *   - pending
     *   - disconnected
     *   - squatted
     *   - demote-skipped
     *   - shared-holder
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ihf blog-migration list --state=pending
     *     wp ihf blog-migration list --state=shared-holder --format=csv
     *
     * @param array $args
     * @param array $assocArgs
     * @return void
     */
    public function listBlogs($args, $assocArgs)
    {
        $state = isset($assocArgs['state']) ? (string) $assocArgs['state'] : '';
        $rows = iHomefinderAdminMigrationStatus::getInstance()->listBlogs($state);
        if ($rows === null) {
            WP_CLI::error('Unknown --state. Use one of: pending, disconnected, squatted, demote-skipped, shared-holder.');
            return;
        }
        $columns = array('blog_id', 'home_url');
        if ($state === iHomefinderAdminMigrationStatus::STATE_SHARED_HOLDER) {
            $columns[] = 'reason';
        }
        $format = isset($assocArgs['format']) ? (string) $assocArgs['format'] : 'table';
        if (!in_array($format, array('table', 'json', 'csv'), true)) {
            $format = 'table';
        }
        \WP_CLI\Utils\format_items($format, $rows, $columns);
    }
}
