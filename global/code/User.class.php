<?php

/**
 * Contains methods for the current logged in user: admin or client. Submission Accounts are handled via that module.
 */

namespace FormTools;


class User
{
    private $lang;
    private $isLoggedIn;
    private $accountType;
    private $accountId;
    private $username;
    private $email;
    private $theme; // default from DB
    private $swatch; // default from DB


    /**
     * This class is instantiated on every page load through Core::init() and available via Core::$user. A user
     * object is always instantiated, even if the user isn't logged in. This provides a consistent interface to
     * find out things like what theme, language etc. should be used.
     *
     * How should this work? We need to store details about the user in sessions (e.g. their ID) but we don't
     * want to query the database for the user on each and every page load. So the constructor here relies on checking
     * sessions to instantiate the user.
     */
    public function __construct() {
        $account_id = Sessions::get("account_id");

        // if the user isn't logged in, set the defaults
        if (empty($account_id)) {
            $this->isLoggedIn = false;

            // the installation process tracks the UI lang
            $lang = Sessions::get("ui_language");
            $this->lang = ($lang) ? $lang : Core::getDefaultLang();

            // TODO. Memoize these by stashin' em in sessions
            $settings = Settings::get(array("default_theme", "default_client_swatch"));
            $this->theme  = $settings["default_theme"];
            $this->swatch = $settings["default_client_swatch"];

        } else {
            $this->isLoggedIn = true;
        }
    }

    /**
     * The login procedure for both administrators and clients in. If successful, redirects them to the
     * appropriate page, otherwise returns an error.
     *
     * @param array   $info $_POST or $_GET containing both "username" and "password" keys, containing that information
     *                for the user trying to log in.
     * @param boolean $login_as_client [optional] This optional parameter is used by administrators
     *                to log in as a particular client, allowing them to view how the account looks,
     *                even if it is disabled.
     * @return string error message string (if error occurs). Otherwise it redirects the user to the
     *                appropriate page, based on account type.
     */
    public function login($info, $login_as_client = false)
    {
        $LANG = Core::$L;
        $root_url = Core::getRootUrl();

        $settings = Settings::get("", "core");
        $username = $info["username"];

        // administrators can log into client accounts to see what they see. They don't require the client's password
        $password = isset($info["password"]) ? $info["password"] : "";

        // extract info about this user's account
        $account_info = Accounts::getAccountByUsername($username);

        // error check user login info
        if (!$login_as_client) {
            if (empty($password)) {
                return $LANG["validation_no_password"];
            }
            if ($account_info["account_status"] == "disabled") {
                return $LANG["validation_account_disabled"];
            }
            if ($account_info["account_status"] == "pending") {
                return $LANG["validation_account_pending"];
            }
            if (empty($username)) {
                return $LANG["validation_account_not_recognized"];
            }

            $password_correct      = (General::encode($password) == $account_info["password"]);
            $temp_password_correct = (General::encode($password) == $account_info["temp_reset_password"]);


            if (!$password_correct && !$temp_password_correct) {

                // if this is a client account and the administrator has enabled the maximum failed login attempts feature,
                // keep track of the count
                $account_settings = Accounts::getAccountSettings($account_info["account_id"]);

                // stores the MAXIMUM number of failed attempts permitted, before the account gets disabled. If the value
                // is empty in either the user account or for the default value, that means the administrator doesn't want
                // to track the failed login attempts
                $max_failed_login_attempts = (isset($account_settings["max_failed_login_attempts"])) ?
                $account_settings["max_failed_login_attempts"] : $settings["default_max_failed_login_attempts"];

                if ($account_info["account_type"] == "client" && !empty($max_failed_login_attempts)) {
                    $num_failed_login_attempts = (isset($account_settings["num_failed_login_attempts"]) && !empty($account_settings["num_failed_login_attempts"])) ?
                    $account_settings["num_failed_login_attempts"] : 0;

                    $num_failed_login_attempts++;

                    if ($num_failed_login_attempts >= $max_failed_login_attempts) {
                        Clients::disableClient($account_info["account_id"]);
                        Accounts::setAccountSettings($account_info["account_id"], array("num_failed_login_attempts" => 0));
                        return $LANG["validation_account_disabled"];
                    } else {
                        Accounts::setAccountSettings($account_info["account_id"], array("num_failed_login_attempts" => $num_failed_login_attempts));
                    }
                }
                return $LANG["validation_wrong_password"];
            }
        }

        extract(Hooks::processHookCalls("main", compact("account_info"), array("account_info")), EXTR_OVERWRITE);

        // all checks out. Log them in, after populating sessions
        //Sessions::set("settings", $settings);
        Sessions::set("account", $account_info);
        Sessions::set("is_logged_in", true, "account");
        Sessions::set("password", General::encode($password), "account"); // this is deliberate [TODO...!]

        Menus::cacheAccountMenu($account_info["account_id"]);

        // if this is an administrator, ensure the API version is up to date
        if ($account_info["account_type"] == "admin") {
            General::updateApiVersion();
        } else {
            Accounts::setAccountSettings($account_info["account_id"], array("num_failed_login_attempts" => 0));
        }

        // for clients, store the forms & form Views that they are allowed to access
        if ($account_info["account_type"] == "client") {
            $_SESSION["ft"]["permissions"] = Clients::getClientFormViews($account_info["account_id"]);
        }

        // if the user just logged in with a temporary password, append some args to pass to the login page
        // so that they will be prompted to changing it upon login
        $reset_password_args = array();
        if (General::encode($password) == $account_info["temp_reset_password"]) {
            $reset_password_args["message"] = "change_temp_password";
        }

        // redirect the user to whatever login page they specified in their settings
        $login_url = Pages::constructPageURL($account_info["login_page"], "", $reset_password_args);
        $login_url = "$root_url{$login_url}";

        if (!$login_as_client) {
            $this->updateLastLoggedIn();
        }

        session_write_close();
        header("Location: $login_url");
        exit;
    }

    public function getLang() {
        return $this->lang;
    }

    public function getTheme() {
        return $this->theme;
    }

    public function getSwatch() {
        return $this->swatch;
    }

    public function isLoggedIn() {
        return $this->isLoggedIn;
    }

    public function getAccountId() {
        return $this->accountId;
    }

    public function getAccountType() {
        return $this->accountType;
    }

    public function getUsername() {
        return $this->username;
    }

    public function getEmail() {
        return $this->email;
    }

    /**
     * Helper function to determine if the user currently logged in is an administrator or not.
     */
    function isAdmin()
    {
//        $account_id = isset($_SESSION["ft"]["account"]["account_id"]) ? $_SESSION["ft"]["account"]["account_id"] : "";
//        if (empty($account_id))
//            return false;
//
//        $account_info = Accounts::getAccountInfo($account_id);
//        if (empty($account_info) || $account_info["account_type"] != "admin")
//            return false;
//
//        return true;
    }


    /**
     * Updates the last logged in date for the currently logged in user.
     */
    private function updateLastLoggedIn()
    {
        $db = Core::$db;
        $db->query("
            UPDATE {PREFIX}accounts
            SET    last_logged_in = :now
            WHERE  account_id = :account_id
        ");
        $db->bindAll(array(
            "now" => General::getCurrentDatetime(),
            "account_id" => $this->accountId
        ));
        $db->execute();
    }


    /**
     * Redirects a logged in user to their login page.
     */
    public function redirectToLoginPage() {
        $root_url = Core::getRootUrl();
        $login_page = Sessions::get("login_page"); //$_SESSION["ft"]["account"]["login_page"];
        $page = Pages::constructPageURL($login_page);
        header("location: {$root_url}$page");
    }


    /**
     * Logs a user out programmatically. This was added in 2.0.0 to replace the logout.php page. It has
     * a couple of benefits: (1) it's smart enough to know what page to go when logging out. Formerly, it
     * would always redirect to the account's logout URL, but there are situations where that's not always
     * desirable - e.g. sessions timeout. (2) it has the option of passing a message flag via the query
     * string.
     *
     * Internally, a user can logout by passing a "?logout" query string to any page in Form Tools.
     *
     * @param string $message_flag if this value is set, it ALWAYS redirects to the login page, so that the
     *   message is displayed. If it isn't set, it redirects to the user's custom logout URL (if defined).
     */
    public function logout($message_flag = "")
    {
        $root_url = Core::getRootUrl();

        // $g_session_type;

        extract(Hooks::processHookCalls("main", array(), array()));

        // this ensures sessions are started
//        if ($g_session_type == "database") {
//            $sess = new SessionManager();
//        }
//        @session_start();

        // first, if $_SESSION["ft"]["admin"] is set, it is an administrator logging out, so just redirect them
        // back to the admin pages
        if (isset($_SESSION["ft"]) && array_key_exists("admin", $_SESSION["ft"])) {
            Administrator::logoutAsClient();
        } else {
            if (!empty($message_flag)) {
                // empty sessions, but be nice about it. Only delete the Form Tools namespaced sessions - any other
                // PHP scripts the user's running right now should be unaffected
                @session_start();
                @session_destroy();
                $_SESSION["ft"] = array();

                // redirect to the login page, passing along the appropriate message flag so the page knows what to display
                $logout_url = General::constructUrl($root_url, "message=$message_flag");
                session_write_close();
                header("location: $logout_url");
                exit;
            } else {
                $logout_url = isset($_SESSION["ft"]["account"]["logout_url"]) ? $_SESSION["ft"]["account"]["logout_url"] : "";

                // empty sessions, but be nice about it. Only delete the Form Tools namespaced sessions - any other
                // PHP scripts the user happens to be running right now should be unaffected
                @session_start();
                @session_destroy();
                $_SESSION["ft"] = array();

                if (empty($logout_url)) {
                    $logout_url = $root_url;
                }

                // redirect to login page
                session_write_close();
                header("location: $logout_url");
                exit;
            }
        }
    }


    /**
     * Provides basic permission checking for accessing the pages.
     *
     * Verifies the user has permission to view the current page. It is used by feeding the minimum account type to
     * view the page - "client", will let administrators and clients view it, but "admin" will only let administrators.
     * If the person doesn't have permission to view the page they are logged out.
     *
     * Should be called on ALL Form Tools pages - including modules.
     *
     * @param string $account_type The account type - "admin" / "client" / "user" (for Submission Accounts module)
     * @param boolean $auto_logout either automatically log the user out if they don't have permission to view the page (or
     *     sessions have expired), or - if set to false, just return the result as a boolean (true = has permission,
     *     false = doesn't have permission)
     * @return array (if $auto_logout is set to false)
     */
    public function checkAuth($required_account_type, $auto_logout = true)
    {
        $db = Core::$db;
        $root_url = Core::getRootUrl();

        $boot_out_user = false;
        $message_flag = "";

        extract(Hooks::processHookCalls("end", compact("account_type"), array("boot_out_user", "message_flag")), EXTR_OVERWRITE);

        $account_id   = Sessions::exists("account_id", "account") ? Sessions::get("account_id", "account") : "";
        $account_type = Sessions::exists("account_type", "account") ? Sessions::get("account_type", "account") : "";

        // some VERY complex logic here. The "user" account permission type is included so that people logged in
        // via the Submission Accounts can still view certain pages, e.g. pages with the Pages module. This checks that
        // IF the minimum account type of the page is a "user", it EITHER has the user account info set (i.e. the submission ID)
        // or it's a regular client or admin account with the account_id set. Crumby, but it'll have to suffice for now.
        if ($this->accountType == "user") {
            if ((!isset($_SESSION["ft"]["account"]["submission_id"]) || empty($_SESSION["ft"]["account"]["submission_id"])) &&
                empty($_SESSION["ft"]["account"]["account_id"])) {
                if ($auto_logout) {
                    header("location: $root_url/modules/submission_accounts/logout.php");
                    exit;
                } else {
                    $boot_out_user = true;
                    $message_flag = "notify_no_account_id_in_sessions";
                }
            }
        }

        // check the user ID is in sessions
        else if (!$account_id || !$account_type) {
            $boot_out_user = true;
            $message_flag = "notify_no_account_id_in_sessions";
        } else if ($account_type == "client" && $required_account_type == "admin") {
            $boot_out_user = true;
            $message_flag = "notify_invalid_permissions";
        } else {
            $db->query("
                SELECT count(*) as c
                FROM {PREFIX}accounts
                WHERE account_id = :account_id AND password = :password
            ");
            $db->bindAll(array(
                "account_id" => $account_id,
                "password" => Sessions::get("password", "account")
            ));
            $db->execute();
            $info = $db->fetch();

            if ($info["c"] != 1) {
                $boot_out_user = true;
                $message_flag = "notify_invalid_account_information_in_sessions";
            }
        }

        if ($boot_out_user && $auto_logout) {
            $this->logout($message_flag);
        } else {
            return array(
                "has_permission" => !$boot_out_user, // we invert it because we want to return TRUE if they have permission
                "message"        => $message_flag
            );
        }
    }

}