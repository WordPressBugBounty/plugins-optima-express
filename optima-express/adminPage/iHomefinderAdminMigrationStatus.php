<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Blog credential migration progress (MAX-4396 Step 12).
 *
 * Multisite: a page under Network Admin > Settings (manage_network_options) built from
 * get_sites() in chunks plus switch_to_blog() and option reads, behind catch (Throwable) per
 * site. Single site: a section on the Register page (manage_options) reading the current blog
 * only. Both share buildReport(), which the WP-CLI command reads too.
 *
 * Everything assembled here is redacted by construction: blog ids, home URLs, states, counts
 * and timestamps. No login, uuid, password, hash or last_ip is ever read into the report.
 *
 * Controls (pause, retirement arm, retirement hold, evaluate retirement now) post to
 * admin-post.php with distinct nonce actions; the authenticated admin_post_ hooks only.
 */
class iHomefinderAdminMigrationStatus
{

    // admin-post action names, each also its nonce action.
    const PAUSE_TOGGLE_ACTION = "ihf_migration_pause_toggle";
    const RETIRE_ARM_TOGGLE_ACTION = "ihf_retire_arm_toggle";
    const RETIRE_HOLD_TOGGLE_ACTION = "ihf_retire_hold_toggle";
    const RETIRE_EVALUATE_ACTION = "ihf_retire_evaluate_now";
    // Form field carrying the desired state of a toggle ("1" or "0"), so a double submit
    // cannot flip it twice.
    const ENABLE_FIELD = "ihf_enable";
    // Query parameter carrying the outcome of a control across the redirect.
    const NOTICE_PARAM = "ihf_notice";

    // get_sites() page size for the network scan, and rows per page in the rendered lists.
    const SITES_PER_CHUNK = 200;
    const LIST_PAGE_SIZE = 50;
    // A demotion whose grace passed longer ago than this and is still not recorded is overdue.
    const DEMOTE_OVERDUE_AFTER = 86400;

    // List states, shared with the WP-CLI command.
    const STATE_PENDING = "pending";
    const STATE_DISCONNECTED = "disconnected";
    const STATE_SQUATTED = "squatted";
    const STATE_DEMOTE_SKIPPED = "demote-skipped";
    const STATE_SHARED_HOLDER = "shared-holder";

    // Why a blog still holds the shared user (shared-holders list).
    const HOLDER_PENDING = "pending";
    const HOLDER_GRACE = "grace";
    const HOLDER_DEMOTE_OVERDUE = "demote-overdue";
    const HOLDER_DEMOTE_SKIPPED = "demote-skipped";
    const HOLDER_DISCONNECTED = "disconnected";
    const HOLDER_UNREGISTERED = "unregistered";
    const HOLDER_PLUGIN_INACTIVE = "plugin-inactive";
    const HOLDER_NO_TICK = "no-tick";

    // Why the shared user's passwords are not (yet) being purged.
    const RETIRE_BLOCK_PAUSED = "paused";
    const RETIRE_BLOCK_HOLD = "hold";
    const RETIRE_BLOCK_SUPERADMIN = "superadmin";
    const RETIRE_BLOCK_COUNT_ERROR = "count-error";
    const RETIRE_BLOCK_LIVE_MEMBERS = "live-members";
    const RETIRE_BLOCK_PROOF = "proof";
    const RETIRE_BLOCK_YOUNG = "young";
    const RETIRE_BLOCK_RECENT_USE = "recent-use";
    const RETIRE_BLOCK_AWAITING_ZERO = "awaiting-zero";

    private static $instance;
    private $admin;

    private function __construct()
    {
        $this->admin = iHomefinderAdmin::getInstance();
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * network_admin_menu handler: the progress page under Network Admin > Settings.
     *
     * @return void
     */
    public function registerNetworkMenu()
    {
        add_submenu_page(
            "settings.php",
            "Optima Express Blog Migration",
            "Optima Express Migration",
            "manage_network_options",
            iHomefinderConstants::PAGE_MIGRATION_STATUS,
            array($this, "renderNetworkPage")
        );
    }

    /**
     * The capability a control or page needs: network options on multisite, options on a
     * single site.
     *
     * @return string
     */
    public function requiredCapability()
    {
        return is_multisite() ? "manage_network_options" : "manage_options";
    }

    /*
     * ------------------------------------------------------------------ data assembly
     */

    /**
     * The state of the current blog as the report sees it. Callers on another blog
     * switch_to_blog() first. Every value is a bool, int, string state or URL; nothing here
     * reads a login, uuid, password or hash.
     *
     * @param int $sharedId the shared user's id when known, to test whether it holds a role here
     * @return array
     */
    public function collectBlogState($sharedId = 0)
    {
        $now = time();
        $blogId = get_current_blog_id();
        $registered = trim((string) get_option(iHomefinderConstants::ACTIVATION_TOKEN_OPTION, '')) !== '';
        $connected = trim((string) get_option(iHomefinderConstants::AUTHENTICATION_TOKEN_OPTION, '')) !== '';
        $previouslyActivated = !empty(get_option(iHomefinderConstants::IS_ACTIVATED_OPTION, false));
        $ticked = (int) get_option(iHomefinderConstants::BLOG_NEXT_CHECK_AT_OPTION, 0) > 0;
        $migrated = trim((string) get_option(iHomefinderConstants::BLOG_SERVICE_PASSWORD_UUID_OPTION, '')) !== '';
        $pending = !empty(get_option(iHomefinderConstants::BLOG_PROVISION_PENDING_OPTION, null));
        $lastError = get_option(iHomefinderConstants::BLOG_PROVISION_LAST_ERROR_OPTION, null);
        $lastErrorStage = '';
        $lastErrorTime = 0;
        if (is_array($lastError)) {
            $lastErrorStage = isset($lastError['stage']) ? (string) $lastError['stage'] : '';
            $lastErrorTime = isset($lastError['time']) ? (int) $lastError['time'] : 0;
        }
        $demotedValue = get_option(iHomefinderConstants::SHARED_USER_DEMOTED_OPTION, null);
        $demoteSkipped = $demotedValue === iHomefinderConstants::SHARED_USER_DEMOTED_SKIPPED;
        $demoted = !$demoteSkipped && is_scalar($demotedValue) && (int) $demotedValue > 0;
        $demoteAfter = (int) get_option(iHomefinderConstants::SHARED_DEMOTE_AFTER_OPTION, 0);
        $demoteOverdue = $demoteAfter > 0 && !$demoted && !$demoteSkipped && ($now - $demoteAfter) > self::DEMOTE_OVERDUE_AFTER;
        $lockValue = get_option(iHomefinderConstants::BLOG_PROVISION_LOCK_OPTION, null);
        $staleLock = false;
        if (is_string($lockValue) && $lockValue !== '') {
            $heldAt = $this->admin->provisionLockHeldAt($lockValue);
            $staleLock = $heldAt === null || ($now - $heldAt) >= iHomefinderAdmin::BLOG_PROVISION_LOCK_TTL;
        }
        $pluginActive = true;
        if (is_multisite()) {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $basename = plugin_basename(dirname(dirname(__FILE__)) . '/iHomefinder.php');
            $pluginActive = is_plugin_active_for_network($basename) || is_plugin_active($basename);
        }
        $holdsShared = false;
        if ($sharedId > 0) {
            if (is_multisite()) {
                $holdsShared = is_user_member_of_blog($sharedId, $blogId);
            } else {
                $shared = get_user_by('ID', $sharedId);
                $holdsShared = $shared instanceof WP_User && !empty($shared->roles);
            }
        }
        $eligible = $registered && $previouslyActivated;
        $state = array(
            'blog_id' => $blogId,
            'home_url' => home_url(),
            'plugin_active' => $pluginActive,
            'registered' => $registered,
            'connected' => $connected,
            'previously_activated' => $previouslyActivated,
            'eligible' => $eligible,
            'ticked' => $ticked,
            'migrated' => $migrated,
            'pending' => $pending,
            'has_last_error' => is_array($lastError) && !empty($lastError),
            'last_error_stage' => $lastErrorStage,
            'last_error_time' => $lastErrorTime,
            'awaiting_https' => !empty(get_option(iHomefinderConstants::BLOG_AWAITING_HTTPS_OPTION, null)),
            'resync_pending' => !empty(get_option(iHomefinderConstants::BLOG_RESYNC_PENDING_OPTION, null)),
            'demoted' => $demoted,
            'demote_skipped' => $demoteSkipped,
            'demote_after' => $demoteAfter,
            'demote_overdue' => $demoteOverdue,
            'version_stamped' => get_option(iHomefinderConstants::VERSION_OPTION, null) === iHomefinderConstants::VERSION,
            'stale_lock' => $staleLock,
            'disconnected' => $registered && !$connected,
            'legacy_squatted' => !empty(get_option(iHomefinderConstants::BLOG_PROVISION_SQUATTED_OPTION, null)),
            'holds_shared' => $holdsShared,
            'holder_reason' => null,
        );
        if ($holdsShared) {
            $state['holder_reason'] = $this->holderReason($state);
        }
        return $state;
    }

    /**
     * Why the shared user still holds a role on a blog, from that blog's state.
     *
     * @param array $s
     * @return string
     */
    private function holderReason($s)
    {
        if (!$s['plugin_active']) {
            return self::HOLDER_PLUGIN_INACTIVE;
        }
        if (!$s['registered']) {
            return self::HOLDER_UNREGISTERED;
        }
        if (!$s['connected']) {
            return self::HOLDER_DISCONNECTED;
        }
        if ($s['pending']) {
            return self::HOLDER_PENDING;
        }
        if ($s['demote_skipped']) {
            return self::HOLDER_DEMOTE_SKIPPED;
        }
        if ($s['demote_overdue']) {
            return self::HOLDER_DEMOTE_OVERDUE;
        }
        if (!$s['ticked']) {
            return self::HOLDER_NO_TICK;
        }
        if (!$s['migrated']) {
            return self::HOLDER_PENDING;
        }
        return self::HOLDER_GRACE;
    }

    /**
     * The full report: counts, the four paginated lists, the controls' state and the shared
     * user block. Multisite scans every live site in chunks; single site reads the current
     * blog. A site whose read throws is counted in sites_failed and skipped.
     *
     * @return array
     */
    public function buildReport()
    {
        $now = time();
        $sharedId = $this->sharedUserId();
        $counts = array(
            'eligible' => 0,
            'eligible_with_traffic' => 0,
            'migrated' => 0,
            'awaiting_https' => 0,
            'resync_pending' => 0,
            'demoted' => 0,
            'demote_skipped' => 0,
            'demote_overdue' => 0,
            'version_stamped' => 0,
            'stale_locks' => 0,
            'pending' => 0,
            'pending_with_last_error' => 0,
            'disconnected' => 0,
            'legacy_squatted' => 0,
            'shared_holders' => 0,
        );
        $lists = array(
            self::STATE_PENDING => array(),
            self::STATE_DISCONNECTED => array(),
            self::STATE_SQUATTED => array(),
            self::STATE_DEMOTE_SKIPPED => array(),
            self::STATE_SHARED_HOLDER => array(),
        );
        $scanned = 0;
        $failed = 0;
        $states = array();
        if (is_multisite()) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            $offset = 0;
            do {
                $sites = get_sites(array(
                    'deleted' => 0,
                    'archived' => 0,
                    'spam' => 0,
                    'number' => self::SITES_PER_CHUNK,
                    'offset' => $offset,
                    'orderby' => 'id',
                    'order' => 'ASC',
                ));
                if (!is_array($sites)) {
                    break;
                }
                foreach ($sites as $site) {
                    $blogId = (int) $site->blog_id;
                    switch_to_blog($blogId);
                    try {
                        $states[] = $this->collectBlogState($sharedId);
                    } catch (Throwable $e) {
                        $failed++;
                        error_log(sprintf('[Optima Express] migration status: could not read blog %d (%s)', $blogId, get_class($e)));
                    } finally {
                        // Each switched blog primes its own alloptions; drop it so a scan of a
                        // large network does not hold every blog's options in memory.
                        wp_cache_delete('alloptions', 'options');
                        wp_cache_delete('notoptions', 'options');
                        restore_current_blog();
                    }
                    $scanned++;
                }
                $offset += self::SITES_PER_CHUNK;
            } while (count($sites) === self::SITES_PER_CHUNK);
        } else {
            try {
                $states[] = $this->collectBlogState($sharedId);
            } catch (Throwable $e) {
                $failed++;
                error_log(sprintf('[Optima Express] migration status: could not read blog %d (%s)', get_current_blog_id(), get_class($e)));
            }
            $scanned = 1;
        }
        foreach ($states as $s) {
            $row = array('blog_id' => $s['blog_id'], 'home_url' => $s['home_url']);
            if ($s['eligible']) {
                $counts['eligible']++;
                if ($s['ticked']) {
                    $counts['eligible_with_traffic']++;
                }
            }
            if ($s['migrated']) {
                $counts['migrated']++;
            }
            if ($s['awaiting_https']) {
                $counts['awaiting_https']++;
            }
            if ($s['resync_pending']) {
                $counts['resync_pending']++;
            }
            if ($s['demoted']) {
                $counts['demoted']++;
            }
            if ($s['demote_skipped']) {
                $counts['demote_skipped']++;
                $lists[self::STATE_DEMOTE_SKIPPED][] = $row;
            }
            if ($s['demote_overdue']) {
                $counts['demote_overdue']++;
            }
            if ($s['version_stamped']) {
                $counts['version_stamped']++;
            }
            if ($s['stale_lock']) {
                $counts['stale_locks']++;
            }
            if ($s['pending']) {
                $counts['pending']++;
                $lists[self::STATE_PENDING][] = $row;
                if ($s['has_last_error']) {
                    $counts['pending_with_last_error']++;
                }
            }
            if ($s['disconnected']) {
                $counts['disconnected']++;
                $lists[self::STATE_DISCONNECTED][] = $row;
            }
            if ($s['legacy_squatted']) {
                $counts['legacy_squatted']++;
                $lists[self::STATE_SQUATTED][] = $row;
            }
            if ($s['holds_shared']) {
                $counts['shared_holders']++;
                $lists[self::STATE_SHARED_HOLDER][] = $row + array('reason' => $s['holder_reason']);
            }
        }
        return array(
            'generated_at' => $now,
            'multisite' => is_multisite(),
            'sites_scanned' => $scanned,
            'sites_failed' => $failed,
            'counts' => $counts,
            'lists' => $lists,
            'controls' => $this->controlsState(),
            'shared' => $this->sharedUserBlock($sharedId, $now),
        );
    }

    /**
     * Rows of one list state, for the WP-CLI command.
     *
     * @param string $state one of the STATE_* values
     * @return array|null null for an unknown state
     */
    public function listBlogs($state)
    {
        $report = $this->buildReport();
        return isset($report['lists'][$state]) ? $report['lists'][$state] : null;
    }

    /**
     * The shared user's id: the network pin when set, else the login lookup (not pinned yet).
     *
     * @return int
     */
    private function sharedUserId()
    {
        $pinned = (int) get_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, 0);
        if ($pinned > 0) {
            return $pinned;
        }
        $shared = $this->admin->findSharedLogin();
        return $shared instanceof WP_User ? (int) $shared->ID : 0;
    }

    /**
     * @return array {disabled_by_constant, paused, paused_at, armed, armed_at, hold, hold_at}
     */
    private function controlsState()
    {
        $paused = get_site_option(iHomefinderConstants::BLOG_MIGRATION_PAUSED_SITE_OPTION, null);
        $armed = get_site_option(iHomefinderConstants::SHARED_RETIRE_ARMED_SITE_OPTION, null);
        $hold = get_site_option(iHomefinderConstants::SHARED_RETIRE_HOLD_SITE_OPTION, null);
        return array(
            'disabled_by_constant' => defined('IHF_BLOG_MIGRATION_DISABLED') && IHF_BLOG_MIGRATION_DISABLED,
            'paused' => !empty($paused),
            'paused_at' => is_scalar($paused) ? (int) $paused : 0,
            'armed' => !empty($armed),
            'armed_at' => is_scalar($armed) ? (int) $armed : 0,
            'hold' => !empty($hold),
            'hold_at' => is_scalar($hold) ? (int) $hold : 0,
        );
    }

    /**
     * The shared user block: id, live count and its time, zero_since with the scheduled purge
     * date or the blocking reason, password counts by family, and the last purge record. The
     * password row is read once, and only its name family, created and last_used are looked at.
     *
     * @param int $sharedId
     * @param int $now
     * @return array
     */
    private function sharedUserBlock($sharedId, $now)
    {
        $pinned = (int) get_site_option(iHomefinderConstants::SHARED_USER_ID_SITE_OPTION, 0) > 0;
        $liveCount = get_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_SITE_OPTION, null);
        $liveCount = is_scalar($liveCount) && $liveCount !== '' ? (int) $liveCount : null;
        $liveCountAt = (int) get_site_option(iHomefinderConstants::SHARED_LIVE_COUNT_AT_SITE_OPTION, 0);
        $zeroSince = (int) get_site_option(iHomefinderConstants::SHARED_ZERO_SINCE_SITE_OPTION, 0);
        $zeroDays = defined('IHF_SHARED_RETIRE_ZERO_DAYS') ? (int) IHF_SHARED_RETIRE_ZERO_DAYS : 7;
        $retiredAt = (int) get_site_option(iHomefinderConstants::SHARED_RETIRED_AT_SITE_OPTION, 0);
        $controls = $this->controlsState();

        $passwordCount = 0;
        $familyCount = 0;
        $foreignCount = 0;
        $young = false;
        $recentUse = false;
        $proof = false;
        $superAdmin = false;
        if ($sharedId > 0) {
            $proof = get_user_meta($sharedId, iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META, true) === '1';
            $superAdmin = is_super_admin($sharedId) || has_filter('pre_get_blogs_of_user');
            if (class_exists('WP_Application_Passwords')) {
                $this->admin->clearUserMetaCache($sharedId);
                foreach (WP_Application_Passwords::get_user_application_passwords($sharedId) as $entry) {
                    $passwordCount++;
                    $name = isset($entry['name']) ? (string) $entry['name'] : '';
                    if ($this->admin->isPluginFamilyPasswordName($name)) {
                        $familyCount++;
                    } else {
                        $foreignCount++;
                    }
                    $created = isset($entry['created']) ? (int) $entry['created'] : 0;
                    $lastUsed = isset($entry['last_used']) ? (int) $entry['last_used'] : 0;
                    if ($created > $now - iHomefinderAdmin::REVOKE_AGE_FLOOR) {
                        $young = true;
                    }
                    if ($created > $now - iHomefinderAdmin::SHARED_RETIRE_RECENT_USE || $lastUsed > $now - iHomefinderAdmin::SHARED_RETIRE_RECENT_USE) {
                        $recentUse = true;
                    }
                }
            }
        }

        $blocking = null;
        $purgeScheduledAt = 0;
        if ($retiredAt === 0) {
            if ($controls['disabled_by_constant'] || $controls['paused']) {
                $blocking = self::RETIRE_BLOCK_PAUSED;
            } elseif ($controls['hold']) {
                $blocking = self::RETIRE_BLOCK_HOLD;
            } elseif ($superAdmin) {
                $blocking = self::RETIRE_BLOCK_SUPERADMIN;
            } elseif ($liveCount !== null && $liveCount < 0) {
                $blocking = self::RETIRE_BLOCK_COUNT_ERROR;
            } elseif ($liveCount !== null && $liveCount > 0) {
                $blocking = self::RETIRE_BLOCK_LIVE_MEMBERS;
            } elseif (!$proof) {
                $blocking = self::RETIRE_BLOCK_PROOF;
            } elseif ($young) {
                $blocking = self::RETIRE_BLOCK_YOUNG;
            } elseif ($recentUse) {
                $blocking = self::RETIRE_BLOCK_RECENT_USE;
            } elseif ($zeroSince <= 0) {
                $blocking = self::RETIRE_BLOCK_AWAITING_ZERO;
            } else {
                $purgeScheduledAt = $zeroSince + $zeroDays * 86400;
            }
        }

        $summary = get_site_option(iHomefinderConstants::SHARED_RETIRED_SUMMARY_SITE_OPTION, null);
        $summaryOut = null;
        if (is_array($summary)) {
            // Scalars and the blog id list only; the per-password triples stay in the option.
            $summaryOut = array();
            foreach ($summary as $key => $value) {
                if ($key === 'entries') {
                    continue;
                }
                if ($key === 'blog_ids' && is_array($value)) {
                    $summaryOut[$key] = array_map('intval', $value);
                } elseif (is_scalar($value) || $value === null) {
                    $summaryOut[$key] = $value;
                }
            }
        }

        return array(
            'user_id' => $sharedId,
            'pinned' => $pinned,
            'live_count' => $liveCount,
            'live_count_at' => $liveCountAt,
            'zero_since' => $zeroSince,
            'zero_days' => $zeroDays,
            'purge_scheduled_at' => $purgeScheduledAt,
            'blocking_reason' => $blocking,
            'password_count' => $passwordCount,
            'family_password_count' => $familyCount,
            'foreign_password_count' => $foreignCount,
            'armed' => $controls['armed'],
            'hold' => $controls['hold'],
            'retired_at' => $retiredAt,
            'retired_count' => (int) get_site_option(iHomefinderConstants::SHARED_RETIRED_COUNT_SITE_OPTION, 0),
            'retired_by' => (string) get_site_option(iHomefinderConstants::SHARED_RETIRED_BY_SITE_OPTION, ''),
            'summary' => $summaryOut,
        );
    }

    /*
     * ------------------------------------------------------------------ controls
     */

    public function handlePauseToggle()
    {
        $this->handleToggle(self::PAUSE_TOGGLE_ACTION, iHomefinderConstants::BLOG_MIGRATION_PAUSED_SITE_OPTION, 'pause');
    }

    public function handleRetireArmToggle()
    {
        $this->handleToggle(self::RETIRE_ARM_TOGGLE_ACTION, iHomefinderConstants::SHARED_RETIRE_ARMED_SITE_OPTION, 'arm');
    }

    public function handleRetireHoldToggle()
    {
        $this->handleToggle(self::RETIRE_HOLD_TOGGLE_ACTION, iHomefinderConstants::SHARED_RETIRE_HOLD_SITE_OPTION, 'hold');
    }

    /**
     * Runs one retirement evaluation now (bypassing the daily claim; the network lock and every
     * guard still apply) and reports whether a purge happened.
     *
     * @return void
     */
    public function handleRetireEvaluateNow()
    {
        check_admin_referer(self::RETIRE_EVALUATE_ACTION);
        $this->requireCapability();
        $purged = $this->admin->retireSharedUserIfDue('admin', true);
        error_log(sprintf('[Optima Express] retirement evaluated on request by user %d: %s', get_current_user_id(), $purged ? 'purged' : 'no purge'));
        $this->redirectBack($purged ? 'retire-purged' : 'retire-evaluated');
    }

    /**
     * One toggle: the nonce, the capability, then set (time()) or clear the network option
     * according to the ENABLE_FIELD value posted, and redirect back with a notice.
     *
     * @param string $action
     * @param string $option
     * @param string $noticeKey
     * @return void
     */
    private function handleToggle($action, $option, $noticeKey)
    {
        check_admin_referer($action);
        $this->requireCapability();
        $enable = isset($_POST[self::ENABLE_FIELD]) && (string) $_POST[self::ENABLE_FIELD] === '1';
        if ($enable) {
            update_site_option($option, time());
        } else {
            delete_site_option($option);
        }
        error_log(sprintf('[Optima Express] migration control %s %s by user %d', $noticeKey, $enable ? 'enabled' : 'cleared', get_current_user_id()));
        $this->redirectBack($noticeKey . ($enable ? '-on' : '-off'));
    }

    private function requireCapability()
    {
        if (!current_user_can($this->requiredCapability())) {
            wp_die('You do not have sufficient permissions to change Optima Express migration settings.');
        }
    }

    /**
     * The page a control returns to: the network page on multisite, the Register page on a
     * single site.
     *
     * @return string
     */
    public function pageUrl()
    {
        if (is_multisite()) {
            return network_admin_url('settings.php?page=' . iHomefinderConstants::PAGE_MIGRATION_STATUS);
        }
        return admin_url('admin.php?page=' . iHomefinderConstants::PAGE_ACTIVATE);
    }

    private function redirectBack($notice)
    {
        wp_safe_redirect(add_query_arg(self::NOTICE_PARAM, $notice, $this->pageUrl()));
        exit;
    }

    /*
     * ------------------------------------------------------------------ rendering
     */

    /**
     * The network admin page.
     *
     * @return void
     */
    public function renderNetworkPage()
    {
        if (!current_user_can('manage_network_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        $report = $this->buildReport();
        ?>
        <div class="wrap" id="ihf-migration-status">
            <h1>Optima Express Blog Migration</h1>
            <?php $this->renderNotice(); ?>
            <p>Generated <?php echo esc_html($this->formatTime($report['generated_at'])); ?>.
                <?php echo esc_html(sprintf('%d sites scanned', $report['sites_scanned'])); ?><?php if ($report['sites_failed'] > 0) { echo esc_html(sprintf(', %d could not be read', $report['sites_failed'])); } ?>.</p>
            <?php $this->renderCounts($report['counts']); ?>
            <?php $this->renderControls($report['controls']); ?>
            <?php $this->renderSharedBlock($report['shared']); ?>
            <?php $this->renderList('Pending', self::STATE_PENDING, $report['lists'][self::STATE_PENDING], 'pending'); ?>
            <?php $this->renderList('Disconnected (key present, no authentication token)', self::STATE_DISCONNECTED, $report['lists'][self::STATE_DISCONNECTED], 'disconnected'); ?>
            <?php $this->renderList('Legacy squatted', self::STATE_SQUATTED, $report['lists'][self::STATE_SQUATTED], 'squatted'); ?>
            <?php $this->renderList('Demotion skipped (ownership unverifiable)', self::STATE_DEMOTE_SKIPPED, $report['lists'][self::STATE_DEMOTE_SKIPPED], 'skipped'); ?>
            <?php $this->renderList('Shared user still holds a role', self::STATE_SHARED_HOLDER, $report['lists'][self::STATE_SHARED_HOLDER], 'holders', true); ?>
        </div>
        <?php
    }

    /**
     * Single site: the section on the Register page. Reads the current blog only.
     *
     * @return void
     */
    public function renderSingleSiteSection()
    {
        if (is_multisite() || !current_user_can('manage_options')) {
            return;
        }
        $report = $this->buildReport();
        ?>
        <h2>Blog Migration</h2>
        <?php $this->renderNotice(); ?>
        <?php $this->renderCounts($report['counts']); ?>
        <?php $this->renderControls($report['controls']); ?>
        <?php $this->renderSharedBlock($report['shared']); ?>
        <?php
    }

    private function renderNotice()
    {
        $notice = isset($_GET[self::NOTICE_PARAM]) ? sanitize_key((string) $_GET[self::NOTICE_PARAM]) : '';
        if ($notice === '') {
            return;
        }
        $messages = array(
            'pause-on' => 'Migration paused. Blogs that still have the shared user stop migrating; blogs without it continue.',
            'pause-off' => 'Migration resumed.',
            'arm-on' => 'Retirement armed. The shared user\'s passwords will be removed at the next evaluation if the ownership proof holds and none was used in the last 90 days.',
            'arm-off' => 'Retirement arm cleared.',
            'hold-on' => 'Retirement hold set. The shared user\'s passwords are kept until the hold is cleared; migration continues.',
            'hold-off' => 'Retirement hold cleared.',
            'retire-purged' => 'Retirement evaluated: the shared user\'s passwords were removed.',
            'retire-evaluated' => 'Retirement evaluated: no purge. See the shared user block for the reason.',
        );
        if (!isset($messages[$notice])) {
            return;
        }
        ?>
        <div class="updated"><p><?php echo esc_html($messages[$notice]); ?></p></div>
        <?php
    }

    private function renderCounts($counts)
    {
        $labels = array(
            'eligible' => 'Eligible (registered and activated)',
            'eligible_with_traffic' => 'Eligible with traffic since the update',
            'migrated' => 'Migrated (per-site credential confirmed)',
            'awaiting_https' => 'Awaiting https',
            'resync_pending' => 'REST base re-sync pending',
            'demoted' => 'Shared user demoted',
            'demote_skipped' => 'Demotion skipped (ownership unverifiable)',
            'demote_overdue' => 'Demotion overdue (more than 24h)',
            'version_stamped' => 'Version stamped',
            'stale_locks' => 'Stale provisioning locks',
            'pending' => 'Pending',
            'pending_with_last_error' => 'Pending with a recorded error',
            'disconnected' => 'Disconnected',
            'legacy_squatted' => 'Legacy squatted',
            'shared_holders' => 'Shared user holds a role',
        );
        ?>
        <table class="widefat striped" style="max-width: 640px;">
            <tbody>
            <?php foreach ($labels as $key => $label) { ?>
                <tr>
                    <td><?php echo esc_html($label); ?></td>
                    <td style="text-align: right;"><?php echo esc_html(number_format_i18n(isset($counts[$key]) ? (int) $counts[$key] : 0)); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php
    }

    private function renderControls($controls)
    {
        ?>
        <h2>Controls</h2>
        <?php if ($controls['disabled_by_constant']) { ?>
            <div class="error"><p>Migration is disabled by the IHF_BLOG_MIGRATION_DISABLED constant. The controls below have no effect until it is removed.</p></div>
        <?php } ?>
        <?php
        $this->renderToggle(self::PAUSE_TOGGLE_ACTION, $controls['paused'], $controls['paused_at'], 'Migration pause', 'Halts migration on blogs that still have the shared user. Blogs without it keep provisioning.', 'Pause migration', 'Resume migration');
        $this->renderToggle(self::RETIRE_HOLD_TOGGLE_ACTION, $controls['hold'], $controls['hold_at'], 'Retirement hold', 'Keeps the shared user\'s passwords in place for an investigation. Blocks both purge paths and nothing else.', 'Set hold', 'Clear hold');
        $this->renderToggle(self::RETIRE_ARM_TOGGLE_ACTION, $controls['armed'], $controls['armed_at'], 'Retirement arm', 'Removes every password on the shared user at the next evaluation, live count notwithstanding, when ownership is proven and none was used in 90 days. Arm only after confirming no site still posts through the shared credential.', 'Arm retirement', 'Clear arm');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 8px 0;">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::RETIRE_EVALUATE_ACTION); ?>" />
            <?php wp_nonce_field(self::RETIRE_EVALUATE_ACTION); ?>
            <button type="submit" class="button" onclick="return confirm('Evaluate the shared user retirement now? Passwords are removed only when every guard passes.');">Evaluate retirement now</button>
        </form>
        <?php
    }

    private function renderToggle($action, $on, $at, $title, $help, $enableLabel, $disableLabel)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 8px 0;">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="<?php echo esc_attr(self::ENABLE_FIELD); ?>" value="<?php echo $on ? '0' : '1'; ?>" />
            <?php wp_nonce_field($action); ?>
            <strong><?php echo esc_html($title); ?>:</strong>
            <?php echo $on ? esc_html('on' . ($at > 0 ? ' since ' . $this->formatTime($at) : '')) : 'off'; ?>
            <button type="submit" class="button"><?php echo esc_html($on ? $disableLabel : $enableLabel); ?></button>
            <span class="description"><?php echo esc_html($help); ?></span>
        </form>
        <?php
    }

    private function renderSharedBlock($shared)
    {
        $reasons = array(
            self::RETIRE_BLOCK_PAUSED => 'migration is paused',
            self::RETIRE_BLOCK_HOLD => 'a retirement hold is set',
            self::RETIRE_BLOCK_SUPERADMIN => 'the shared user is a super admin or a membership filter is present; the count cannot be trusted',
            self::RETIRE_BLOCK_COUNT_ERROR => 'the last membership count errored',
            self::RETIRE_BLOCK_LIVE_MEMBERS => 'the shared user still holds a role on live blogs',
            self::RETIRE_BLOCK_PROOF => 'the shared user carries no service-account proof',
            self::RETIRE_BLOCK_YOUNG => 'a shared password was created in the last 800 seconds',
            self::RETIRE_BLOCK_RECENT_USE => 'a shared password was created or used in the last 7 days',
            self::RETIRE_BLOCK_AWAITING_ZERO => 'no zero reading recorded yet',
        );
        ?>
        <h2>Shared user</h2>
        <table class="widefat striped" style="max-width: 640px;">
            <tbody>
            <tr><td>User id</td><td><?php echo $shared['user_id'] > 0 ? esc_html($shared['user_id'] . ($shared['pinned'] ? '' : ' (not pinned yet)')) : 'none found'; ?></td></tr>
            <tr><td>Live memberships</td><td><?php echo $shared['live_count'] === null ? 'not counted yet' : esc_html(($shared['live_count'] < 0 ? 'count error' : $shared['live_count']) . ($shared['live_count_at'] > 0 ? ' at ' . $this->formatTime($shared['live_count_at']) : '')); ?></td></tr>
            <tr><td>Zero since</td><td><?php echo $shared['zero_since'] > 0 ? esc_html($this->formatTime($shared['zero_since'])) : 'n/a'; ?></td></tr>
            <tr><td>Purge</td><td><?php
            if ($shared['retired_at'] > 0) {
                echo esc_html(sprintf('done %s: %d password(s) removed by %s', $this->formatTime($shared['retired_at']), $shared['retired_count'], $shared['retired_by']));
            } elseif ($shared['purge_scheduled_at'] > 0) {
                echo esc_html('scheduled ' . $this->formatTime($shared['purge_scheduled_at']));
            } else {
                $key = $shared['blocking_reason'];
                echo esc_html('blocked: ' . $key . (isset($reasons[$key]) ? ' (' . $reasons[$key] . ')' : ''));
            }
            ?></td></tr>
            <tr><td>Passwords on the shared user</td><td><?php echo esc_html(sprintf('%d (%d plugin-named, %d foreign)', $shared['password_count'], $shared['family_password_count'], $shared['foreign_password_count'])); ?></td></tr>
            <tr><td>Retirement</td><td><?php echo esc_html(($shared['armed'] ? 'armed' : 'automatic') . ($shared['hold'] ? ', on hold' : '')); ?></td></tr>
            </tbody>
        </table>
        <?php if (is_array($shared['summary'])) { ?>
            <h3>Last purge summary</h3>
            <table class="widefat striped" style="max-width: 640px;">
                <tbody>
                <?php foreach ($shared['summary'] as $key => $value) { ?>
                    <tr>
                        <td><?php echo esc_html($key); ?></td>
                        <td><?php
                        if (is_array($value)) {
                            echo esc_html(implode(', ', $value));
                        } elseif (in_array($key, array('at', 'created_min', 'created_max', 'last_used_min', 'last_used_max'), true) && (int) $value > 0) {
                            echo esc_html($this->formatTime((int) $value));
                        } else {
                            echo esc_html((string) $value);
                        }
                        ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
        <?php
    }

    /**
     * One paginated list (blog id, home URL, optionally the reason). Each list has its own
     * page parameter so paging one does not move the others.
     *
     * @param string $title
     * @param string $state
     * @param array $rows
     * @param string $pageParamSuffix
     * @param bool $withReason
     * @return void
     */
    private function renderList($title, $state, $rows, $pageParamSuffix, $withReason = false)
    {
        $param = 'ihf_' . $pageParamSuffix . '_page';
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::LIST_PAGE_SIZE));
        $page = isset($_GET[$param]) ? max(1, min($pages, absint($_GET[$param]))) : 1;
        $slice = array_slice($rows, ($page - 1) * self::LIST_PAGE_SIZE, self::LIST_PAGE_SIZE);
        ?>
        <h2><?php echo esc_html($title); ?> (<?php echo esc_html(number_format_i18n($total)); ?>)</h2>
        <?php if ($total === 0) { ?>
            <p>None.</p>
            <?php return;
        } ?>
        <table class="widefat striped" style="max-width: 900px;">
            <thead><tr><th>Blog id</th><th>Home URL</th><?php if ($withReason) { ?><th>Reason</th><?php } ?></tr></thead>
            <tbody>
            <?php foreach ($slice as $row) { ?>
                <tr>
                    <td><?php echo esc_html($row['blog_id']); ?></td>
                    <td><?php echo esc_html($row['home_url']); ?></td>
                    <?php if ($withReason) { ?><td><?php echo esc_html(isset($row['reason']) ? $row['reason'] : ''); ?></td><?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($pages > 1) { ?>
            <p>
                <?php echo esc_html(sprintf('Page %d of %d', $page, $pages)); ?>
                <?php if ($page > 1) { ?><a href="<?php echo esc_url(add_query_arg($param, $page - 1, $this->pageUrl())); ?>">Previous</a><?php } ?>
                <?php if ($page < $pages) { ?><a href="<?php echo esc_url(add_query_arg($param, $page + 1, $this->pageUrl())); ?>">Next</a><?php } ?>
            </p>
        <?php } ?>
        <?php
    }

    private function formatTime($time)
    {
        $format = trim((string) get_option('date_format', 'Y-m-d') . ' ' . (string) get_option('time_format', 'H:i'));
        return date_i18n($format, (int) $time);
    }
}
