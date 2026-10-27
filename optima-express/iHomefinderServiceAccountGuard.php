<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Scopes and hardens the plugin's service accounts: every user carrying the
 * ihf_service_account meta, which is each site's own Optima Express service user and, until
 * a site has migrated, the legacy shared one. The account exists so that the service can
 * publish blog posts through the REST API with an application password, and nothing else:
 *
 *  - REST: only POST /optima-express/v1/blog-post, GET /optima-express/v1/authors,
 *    POST /wp/v2/media and GET /wp/v2/users/me; anything else is a 403 whose body never
 *    names the account.
 *  - No login surfaces: the interactive login rejects it, password reset is disabled, XML-RPC
 *    rejects its application password, and a request that did not authenticate with an
 *    application password (a cookie, for instance) is treated as anonymous.
 *  - It cannot edit its own user record (the CVE-2026-93901 lineage: an email change with no
 *    confirmation), can upload images only, and keeps application passwords available for
 *    itself even when a site disables them for users in general.
 *  - The login "optima-express" and the prefix "optima-express-" are reserved, so a visitor or
 *    a network sign-up cannot squat a name the plugin relies on.
 *
 * Every test is get_user_meta() === '1', memoized per request; current_user_can() is never
 * used to decide who the account is. Nothing here writes.
 */
class iHomefinderServiceAccountGuard
{

    // WP_Error codes returned to callers; their messages are fixed strings.
    const REST_FORBIDDEN_CODE = 'ihf_service_account_forbidden';
    const LOGIN_DENIED_CODE = 'ihf_service_account_login';
    const XMLRPC_DENIED_CODE = 'ihf_service_account_xmlrpc';
    const RESERVED_LOGIN_CODE = 'ihf_reserved_login';

    // "METHOD /route" pairs the account may call. Compared after upper-casing the method and
    // lower-casing the route with any trailing slash removed.
    private static $allowedRestRoutes = array(
        'POST /optima-express/v1/blog-post',
        'GET /optima-express/v1/authors',
        'POST /wp/v2/media',
        'GET /wp/v2/users/me',
    );

    // Mime types the account may upload (matched on the value, so a host that renamed the
    // extension keys still gets the intersection rather than an empty list).
    private static $allowedUploadMimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');

    private static $instance;
    // Per-request memo of isServiceAccount(), keyed by user id.
    private $memo = array();

    private function __construct()
    {
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Binds every hook. Called once at plugin load, outside any is_admin() branch: REST,
     * XML-RPC and the login form are not admin requests.
     *
     * @return void
     */
    public function register()
    {
        add_filter('rest_pre_dispatch', array($this, 'restrictRestRoutes'), 10, 3);
        add_filter('map_meta_cap', array($this, 'denySelfEdit'), 10, 4);
        add_action('wp_authenticate_application_password_errors', array($this, 'rejectXmlRpc'), 10, 4);
        add_filter('allow_password_reset', array($this, 'denyPasswordReset'), 10, 2);
        add_filter('wp_authenticate_user', array($this, 'denyInteractiveLogin'), 10, 2);
        // After core's cookie (10, 20) and application password (20) validators.
        add_filter('determine_current_user', array($this, 'requireApplicationPassword'), 30);
        add_filter('upload_mimes', array($this, 'restrictUploadMimes'), 10, 2);
        add_filter('wp_is_application_passwords_available_for_user', array($this, 'keepApplicationPasswordsAvailable'), 10, 2);
        add_filter('illegal_user_logins', array($this, 'reserveLogins'));
        add_filter('registration_errors', array($this, 'rejectReservedRegistration'), 10, 2);
        add_filter('wpmu_validate_user_signup', array($this, 'rejectReservedSignup'));
    }

    /**
     * True when $userId carries the service-account meta. Read-only, memoized.
     *
     * @param int $userId
     * @return bool
     */
    public function isServiceAccount($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        if (!array_key_exists($userId, $this->memo)) {
            $this->memo[$userId] = get_user_meta($userId, iHomefinderConstants::BLOG_SERVICE_ACCOUNT_META, true) === '1';
        }
        return $this->memo[$userId];
    }

    /**
     * True for the legacy shared login and for anything under the per-site prefix.
     *
     * @param mixed $login
     * @return bool
     */
    public function isReservedLogin($login)
    {
        if (!is_scalar($login)) {
            return false;
        }
        $login = strtolower(trim((string) $login));
        if ($login === '') {
            return false;
        }
        if ($login === strtolower(iHomefinderConstants::SHARED_USER_LOGIN)) {
            return true;
        }
        return strpos($login, strtolower(iHomefinderConstants::SERVICE_USER_LOGIN_PREFIX)) === 0;
    }

    /**
     * rest_pre_dispatch: a service account may call the four allowed method + route pairs
     * and nothing else.
     *
     * @param mixed           $result
     * @param WP_REST_Server  $server
     * @param WP_REST_Request $request
     * @return mixed
     */
    public function restrictRestRoutes($result, $server, $request)
    {
        if ($result !== null) {
            return $result;
        }
        $userId = get_current_user_id();
        if (!$this->isServiceAccount($userId)) {
            return $result;
        }
        $call = strtoupper((string) $request->get_method()) . ' ' . strtolower(rtrim((string) $request->get_route(), '/'));
        if (in_array($call, self::$allowedRestRoutes, true)) {
            return $result;
        }
        return new WP_Error(
            self::REST_FORBIDDEN_CODE,
            'This account may only use the Optima Express blog endpoints.',
            array('status' => 403)
        );
    }

    /**
     * map_meta_cap: a service account may not edit its own user record. Core lets any user
     * edit themselves; that is the path an email change would take.
     *
     * @param string[] $caps
     * @param string   $cap
     * @param int      $userId
     * @param array    $args
     * @return string[]
     */
    public function denySelfEdit($caps, $cap, $userId, $args)
    {
        if ($cap !== 'edit_user' || empty($args) || !isset($args[0])) {
            return $caps;
        }
        $target = (int) $args[0];
        if ($target > 0 && $target === (int) $userId && $this->isServiceAccount($target)) {
            $caps[] = 'do_not_allow';
        }
        return $caps;
    }

    /**
     * wp_authenticate_application_password_errors: an application password of a service
     * account does not authenticate an XML-RPC request.
     *
     * @param WP_Error $error
     * @param WP_User  $user
     * @param array    $item
     * @param string   $password
     * @return void
     */
    public function rejectXmlRpc($error, $user, $item, $password)
    {
        if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) {
            return;
        }
        if (!($user instanceof WP_User) || !$this->isServiceAccount($user->ID)) {
            return;
        }
        if ($error instanceof WP_Error) {
            $error->add(self::XMLRPC_DENIED_CODE, 'This account may not use XML-RPC.');
        }
    }

    /**
     * allow_password_reset: never for a service account.
     *
     * @param bool $allow
     * @param int  $userId
     * @return bool
     */
    public function denyPasswordReset($allow, $userId)
    {
        if ($this->isServiceAccount($userId)) {
            return false;
        }
        return $allow;
    }

    /**
     * wp_authenticate_user: the login form (and anything else that authenticates a user with
     * its account password) rejects a service account.
     *
     * @param WP_User|WP_Error $user
     * @param string           $password
     * @return WP_User|WP_Error
     */
    public function denyInteractiveLogin($user, $password)
    {
        if ($user instanceof WP_User && $this->isServiceAccount($user->ID)) {
            return new WP_Error(self::LOGIN_DENIED_CODE, 'This account cannot sign in.');
        }
        return $user;
    }

    /**
     * determine_current_user at priority 30: a service account is the current user only when
     * this request authenticated with an application password. A cookie session (or any other
     * validator that resolved to it) is treated as anonymous. Callers of wp_set_current_user()
     * bypass this filter and are out of scope.
     *
     * @param int|false $userId
     * @return int|false
     */
    public function requireApplicationPassword($userId)
    {
        if (!$userId) {
            return $userId;
        }
        if (!$this->isServiceAccount($userId)) {
            return $userId;
        }
        if (did_action('application_password_did_authenticate')) {
            return $userId;
        }
        return 0;
    }

    /**
     * upload_mimes: a service account may upload the four image types only.
     *
     * @param array            $mimes
     * @param int|WP_User|null $user user id, user object, or null for the current user (core
     *                               passes all three shapes through get_allowed_mime_types())
     * @return array
     */
    public function restrictUploadMimes($mimes, $user = null)
    {
        if ($user instanceof WP_User) {
            $userId = (int) $user->ID;
        } elseif (is_numeric($user) && (int) $user > 0) {
            $userId = (int) $user;
        } else {
            $userId = get_current_user_id();
        }
        if (!$this->isServiceAccount($userId) || !is_array($mimes)) {
            return $mimes;
        }
        $allowed = array();
        foreach ($mimes as $extensions => $mime) {
            if (in_array($mime, self::$allowedUploadMimes, true)) {
                $allowed[$extensions] = $mime;
            }
        }
        return $allowed;
    }

    /**
     * wp_is_application_passwords_available_for_user: the service account keeps its
     * application password even where a site turned them off per user. This does not rescue
     * a site-wide disable (wp_is_application_passwords_available()).
     *
     * @param bool    $available
     * @param WP_User $user
     * @return bool
     */
    public function keepApplicationPasswordsAvailable($available, $user)
    {
        if ($user instanceof WP_User && $this->isServiceAccount($user->ID)) {
            return true;
        }
        return $available;
    }

    /**
     * illegal_user_logins: the exact shared login is reserved everywhere wp_insert_user()
     * validates a new login. The prefix cannot be expressed here (exact matches only) and is
     * covered by the two sign-up filters below; the plugin's own per-site users are created
     * under the prefix and are unaffected.
     *
     * @param array $logins
     * @return array
     */
    public function reserveLogins($logins)
    {
        $logins = is_array($logins) ? $logins : array();
        if (!in_array(iHomefinderConstants::SHARED_USER_LOGIN, $logins, true)) {
            $logins[] = iHomefinderConstants::SHARED_USER_LOGIN;
        }
        return $logins;
    }

    /**
     * registration_errors (single-site visitor registration).
     *
     * @param WP_Error $errors
     * @param string   $login
     * @return WP_Error
     */
    public function rejectReservedRegistration($errors, $login)
    {
        if ($errors instanceof WP_Error && $this->isReservedLogin($login)) {
            $errors->add(self::RESERVED_LOGIN_CODE, 'That username is reserved. Please choose another.');
        }
        return $errors;
    }

    /**
     * wpmu_validate_user_signup (network sign-up and Users > Add New on multisite).
     *
     * @param array $result user_name, orig_username, user_email, errors
     * @return array
     */
    public function rejectReservedSignup($result)
    {
        if (!is_array($result) || !isset($result['errors']) || !($result['errors'] instanceof WP_Error)) {
            return $result;
        }
        $login = isset($result['user_name']) ? $result['user_name'] : '';
        if ($this->isReservedLogin($login)) {
            $result['errors']->add('user_name', 'That username is reserved. Please choose another.');
        }
        return $result;
    }
}
