<?php

require_once NEWSLETTER_INCLUDES_DIR . '/module.php';

class NewsletterSubscription extends NewsletterModule {

    const VERSION = '1.0.3';
    const MESSAGE_CONFIRMED = 'confirmed';

    static $instance;

    /**
     * @return NewsletterSubscription
     */
    static function instance() {
        if (self::$instance == null) {
            self::$instance = new NewsletterSubscription();
        }
        return self::$instance;
    }

    function __construct() {
        parent::__construct('subscription', self::VERSION);

        add_action('wp_login', array($this, 'hook_wp_login'));
        
        // Must be called after the Newsletter::hook_init, since some constants are defined
        // there.
        add_action('init', array($this, 'hook_init'), 90);
    }

    function hook_init() {
        $action = isset($_REQUEST['na']) ? $_REQUEST['na'] : '';
        if (empty($action) || is_admin()) return;
        
        if ($action == 's') {
            $user = $this->subscribe();
            if ($user->status == 'E')
                $this->show_message('error', $user->id);
            if ($user->status == 'C')
                $this->show_message('confirmation', $user->id);
            if ($user->status == 'S')
                $this->show_message('confirmed', $user->id);
        }
        else if ($action == 'c') {
            $user = NewsletterSubscription::instance()->confirm();
            $this->show_message('confirmed', $user);
        } else if ($action == 'u') {
            $user = $this->get_user_from_request();
            if ($user == null)
                die('No subscriber found.');
            $this->show_message('unsubscription', $user->id);
        }
        else if ($action == 'uc') {
            $user = $this->unsubscribe();
            if ($user->status == 'E') {
                $this->show_message('error', $user->id);
            } else {
                $this->show_message('unsubscribed', $user);
            }
        } else if ($action == 'p' || $action == 'pe') {
            $user = $this->get_user_from_request();
            if ($user == null)
                die('No subscriber found.');
            $this->show_message('profile', $user);
        }
    }

    function upgrade() {
        global $wpdb, $charset_collate, $newsletter;

        parent::upgrade();

        // Migrate the profile_text from profile to subscription options
        $options_profile = $this->get_options('profile');

        if (empty($options_profile)) {
            update_option('newsletter_profile', $this->get_default_options('profile'));
        }

        $default_options = $this->get_default_options();

        if (empty($this->options['error_text'])) {
            $this->options['error_text'] = $default_options['error_text'];
            $this->save_options($this->options);
        }

        // Old migration code
        if (isset($options_profile['profile_text'])) {
            $this->options['profile_text'] = $options_profile['profile_text'];
            if (empty($this->options['profile_text'])) {
                $this->options['profile_text'] = '{profile_form}<p><a href="{unsubscription_url}">I want to unsubscribe.</a>';
            }

            $this->save_options($this->options);
            unset($options_profile['profile_text']);
            update_option('newsletter_profile', $options_profile);
        }

        if (isset($options_profile['profile_saved'])) {
            $this->options['profile_saved'] = $options_profile['profile_saved'];
            $this->save_options($this->options);
            unset($options_profile['profile_saved']);
            update_option('newsletter_profile', $options_profile);
        }

        // Very old...
        if (!isset($this->options['url']) && !empty($newsletter->options['url'])) {
            $this->options['url'] = $newsletter->options['url'];
            $this->save_options($this->options);
        }

        // Because users do not understand how to create an "extensions" folder...
        wp_mkdir_p(WP_CONTENT_DIR . '/extensions/newsletter/subscription');
        return true;
    }

    function admin_menu() {
        $this->add_menu_page('options', 'Subscription Steps');
        $this->add_admin_page('profile', 'Profile');
        $this->add_admin_page('forms', 'Forms');
    }

    /**
     * This method has been redefined for compatibility with the old options naming. It would
     * be better to change them instead. The subscription options should be named
     * "newsletter_subscription" while the form field options, actually named
     * "newsletter_profile", should be renamed "newsletter_subscription_profile" (since
     * they are retrived with get_options('profile')) or "newsletter_subscription_fields" or
     * "newsletter_subscription_form".
     *
     * @param array $options
     * @param string $sub
     */
    function save_options($options, $sub = '') {
        if ($sub == '') {
            // For compatibility the options are wrongly named
            return update_option('newsletter', $options);
        }
        if ($sub == 'profile') {
            return update_option('newsletter_profile', $options);
        }
        return parent::save_options($sub);
    }

    function get_options($sub = '') {
        if ($sub == '') {
            // For compatibility the options are wrongly named
            return get_option('newsletter', array());
        }
        if ($sub == 'profile') {
            // For compatibility the options are wrongly named
            return get_option('newsletter_profile', array());
        }
        return parent::get_options($sub);
    }

    /**
     * See wp-includes/user.php function wp_signon().
     */
    function hook_wp_login($user_login) {
        global $newsletter;

        $this->logger->info(__METHOD__ . '> Start with ' . $user_login);
        $wp_user = get_user_by('login', $user_login);
        if (!empty($wp_user)) {
            $this->logger->info($wp_user);
            // We have a user able to login, so his subscription can be confirmed if not confirmed
            $user = $newsletter->get_user($wp_user->user_email);
            if (!empty($user)) {
                $this->confirm($user->id, $this->options['wp_welcome'] == 1);
            }
        }
        $this->logger->info(__METHOD__ . '> End');
    }

    /**
     * Return the subscribed user.
     *
     * @param bool $registration If invoked from the registration process
     * @global Newsletter $newsletter
     */
    function subscribe($status = null, $emails = true) {
        global $newsletter;

        $options = get_option('newsletter', array());
        $options_profile = get_option('newsletter_profile', array());

        $opt_in = (int) $this->options['noconfirmation']; // 0 - double, 1 - single

        $email = $newsletter->normalize_email(stripslashes($_REQUEST['ne']));
        if ($email == null)
            die('Wrong email');

        $user = $newsletter->get_user($email);

        if ($user != null) {
            if ($user->status == 'B' || $user->status == 'C') {
                $this->logger->error('Subscription of an address with status ' . $user->status);
                $user->status = 'E';
                return $user;
            }
        }

        if ($user != null) {
            $this->logger->info("Email address subscribed but not confirmed");
            $user = array('id' => $user->id);
        } else {
            $this->logger->info("New email address");
            $user = array('email' => $email);
        }

        $user['name'] = $newsletter->normalize_name(stripslashes($_REQUEST['nn']));
        // TODO: required checking

        $user['surname'] = $newsletter->normalize_name(stripslashes($_REQUEST['ns']));
        // TODO: required checking

        if (!empty($_REQUEST['nx']))
            $user['sex'] = $_REQUEST['nx'][0];
        // TODO: valid values check

        $user['referrer'] = $_REQUEST['nr'];
        $user['http_referer'] = $_SERVER['HTTP_REFERER'];

        // New profiles
        for ($i = 1; $i <= NEWSLETTER_PROFILE_MAX; $i++) {
            // If the profile cannot be set by  subscriber, skip it.
            if ($options_profile['profile_' . $i . '_status'] == 0)
                continue;

            $user['profile_' . $i] = trim(stripslashes($_REQUEST['np' . $i]));
        }

        // Preferences (field names are nl[] and values the list number so special forms with radio button can work)
        if (is_array($_REQUEST['nl'])) {
            for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
                // If not zero it is selectable by user (on subscription or on profile)
                if ($options_profile['list_' . $i . '_status'] == 0)
                    continue;
                if (in_array($i, $_REQUEST['nl']))
                    $user['list_' . $i] = 1;
            }
        }
        
        // Forced preferences as set on subscription configuration
        for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
            if (empty($options['preferences_' . $i])) continue;
            $user['list_' . $i] = 1;
        }        

        $user['token'] = $newsletter->get_token();
        $user['ip'] = $_SERVER['REMOTE_ADDR'];
        if ($status != null)
            $user['status'] = $status;
        else
            $user['status'] = $opt_in == 1 ? 'C' : 'S';

        // TODO: add the flow integration?

        if (isset($flow)) {
            $user['flow'] = $flow;
        }

        $user = apply_filters('newsletter_user_subscribe', $user);

        // TODO: should be removed!!!
        if (defined('NEWSLETTER_FEED_VERSION')) {
            $options_feed = get_option('newsletter_feed', array());
            if ($options_feed['add_new'] == 1)
                $user['feed'] = 1;
        }

        $user = $newsletter->save_user($user);

        // Notification to admin (only for new confirmed subscriptions)
        if ($user->status == 'C') {
            $this->notify_admin($user, 'Newsletter subscription');
            setcookie('newsletter', $user->id . '-' . $user->token, time() + 60 * 60 * 24 * 365, '/');
        }

        if (!$emails)
            return $user;


        $prefix = ($user->status == 'C') ? 'confirmed_' : 'confirmation_';
        
        if (empty($options[$prefix . 'disabled'])) {
            $message = $options[$prefix . 'message'];

            // TODO: This is always empty!
            $message_text = $options[$prefix . 'message_text'];
            $subject = $options[$prefix . 'subject'];

            $this->mail($user->email, $newsletter->replace($subject, $user), $newsletter->replace($message, $user));
        }
        return $user;
    }

    /**
     * Send emails during the subscription process. Emails are themes with email.php file.
     * @global type $newsletter
     * @return type
     */
    function mail($to, $subject, $message) {

        ob_start();
        include NEWSLETTER_DIR . '/subscription/email.php';
        $message = ob_get_clean();

        Newsletter::instance()->mail($to, $subject, $message);
    }

    /**
     *
     * @global Newsletter $newsletter
     * @param type $user
     * @return type
     */
    function confirm($user_id = null, $emails = true) {
        global $newsletter;
        if ($user_id == null) {
            $user = $this->get_user_from_request();
        } else {
            $user = $newsletter->get_user($user_id);
        }

        if ($user == null)
            die('No subscriber found.');
        if ($user->status != 'S') {
            $user->status = 'E';
            return $user;
        }
        setcookie('newsletter', $user->id . '-' . $user->token, time() + 60 * 60 * 24 * 365, '/');
        $newsletter->set_user_status($user->id, 'C');
        $user->status = 'C';
        $this->notify_admin($user, 'Newsletter subscription');

        if (!$emails)
            return $user;
        
        if (empty($this->options['confirmed_disabled'])) {
            $message = $this->options['confirmed_message'];
            // TODO: This is always empty!
            $message_text = $this->options['confirmed_message_text'];
            $subject = $this->options['confirmed_subject'];

            $this->mail($user->email, $newsletter->replace($subject, $user), $newsletter->replace($message, $user));
        }

        return $user;
    }

    /**
     * Returns the unsubscribed user.
     *
     * @global type $newsletter
     * @return type
     */
    function unsubscribe() {
        global $newsletter;
        $user = $this->get_user_from_request();
        setcookie('newsletter', '', time() - 3600);
        if ($user == null) {
            die('Subscriber not found');
        }
        if ($user->status != 'C') {
            $user->status = 'E';
            return $user;
        }

        $newsletter->set_user_status($user->id, 'U');

        $this->mail($user->email, $newsletter->replace($this->options['unsubscribed_subject'], $user), $newsletter->replace($this->options['unsubscribed_message'], $user));
        $this->notify_admin($user, 'Newsletter unsubscription');

        return $user;
    }

    function save_profile() {
        global $newsletter;

        $user = $this->get_user_from_request();
        if ($user == null)
            die('No subscriber found.');


        $options_profile = get_option('newsletter_profile', array());
        $options_main = get_option('newsletter_main', array());

        if (!$newsletter->is_email($_REQUEST['ne']))
            die('Wrong email address.');

        // General data
        $data['email'] = $newsletter->normalize_email(stripslashes($_REQUEST['ne']));
        $data['name'] = $newsletter->normalize_name(stripslashes($_REQUEST['nn']));
        $data['surname'] = $newsletter->normalize_name(stripslashes($_REQUEST['ns']));
        if ($options_profile['sex_status'] >= 1) {
            $data['sex'] = $_REQUEST['nx'][0];
            // Wrong data injection check
            if ($data['sex'] != 'm' && $data['sex'] != 'f' && $data['sex'] != 'n')
                die('Wrong sex field');
        }

        // Lists. If not list is present or there is no list to choose or all are unchecked.
        $nl = $_REQUEST['nl'];
        if (!is_array($nl))
            $nl = array();

        // For each preference which an be edited (and so is present on profile form)...
        for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
            if ($options_profile['list_' . $i . '_status'] == 0)
                continue;
            $data['list_' . $i] = in_array($i, $nl) ? 1 : 0;
        }

        // Profile
        for ($i = 1; $i <= NEWSLETTER_PROFILE_MAX; $i++) {
            if ($options_profile['profile_' . $i . '_status'] == 0)
                continue;
            $data['profile_' . $i] = stripslashes($_REQUEST['np' . $i]);
        }

        $data['id'] = $user->id;

        // Feed by Mail service is saved here
        $data = apply_filters('newsletter_profile_save', $data);
        //var_dump($data);

        $user = $newsletter->save_user($data);
        return $user;
    }

    /**
     * Finds the right way to show the message identified by $key (welcome, unsubscription, ...) redirecting the user to the
     * WordPress page or loading the configured url or activating the standard page.
     *
     * @global Newsletter $newsletter
     */
    function show_message($key, $user, $alert = '') {
        global $newsletter;

        if (!is_object($user)) {
            if (is_array($user))
                $user = (object) $user;
            else
                $user = $newsletter->get_user($user);
        }

        if (!empty($alert))
            $params = '&alert=' . urlencode($alert);

        // Add exceptions for "profile" key.
        // Is there a custom url?
        if (!empty($this->options[$key . '_url'])) {
            header('Location: ' . self::add_qs($this->options[$key . '_url'], 'nk=' . $user->id . '-' . $user->token, false) . $params);
            die();
        }

        // Is there a dedicated page?
        if (!empty($this->options['url'])) {
            header('Location: ' . self::add_qs($this->options['url'], 'nm=' . $key . '&nk=' . $user->id . '-' . $user->token, false) . $params);
            die();
        }

        // Use the standard page.
        header('Location: ' . plugins_url('newsletter') . '/subscription/page.php?nm=' . $key . '&nk=' . $user->id . '-' . $user->token . $params);
        die();
    }

    /**
     * Loads the user using the request parameters (nk or nt and ni).
     *
     * @global Newsletter $newsletter
     * @return null
     */
    function get_user_from_request() {
        global $newsletter;

        if (isset($_REQUEST['nk'])) {
            list($id, $token) = @explode('-', $_REQUEST['nk'], 2);
        } else if (isset($_REQUEST['ni'])) {
            $id = (int) $_REQUEST['ni'];
            $token = $_REQUEST['nt'];
        }
        $user = $newsletter->get_user($id);

        if ($user == null || $token != $user->token)
            return null;
        return $user;
    }

    function get_message_key_from_request() {
        if (empty($_GET['nm']))
            return 'subscription';
        $key = $_GET['nm'];
        switch ($key) {
            case 's': return 'confirmation';
            case 'c': return 'confirmed';
            case 'u': return 'unsubscription';
            case 'uc': return 'unsubscribed';
            case 'p':
            case 'pe':
                return 'profile';
            default: return $key;
        }
    }

    /** Searches for a user using the nk parameter or the ni and nt parameters. Tries even with the newsletter cookie.
     * If found, the user object is returned or null.
     * The user is returned without regards to his status that should be checked by caller.
     *
     * @global wpdb $wpdb
     * @global type $current_user
     * @global Newsletter $newsletter
     * @return null
     */
    function check_user() {
        global $wpdb, $current_user, $newsletter;

        if (isset($_REQUEST['nk'])) {
            list($id, $token) = @explode('-', $_REQUEST['nk'], 2);
        } else if (isset($_REQUEST['ni'])) {
            $id = (int) $_REQUEST['ni'];
            $token = $_REQUEST['nt'];
        } else if (isset($_COOKIE['newsletter'])) {
            list ($id, $token) = @explode('-', $_COOKIE['newsletter'], 2);
        }

        if (is_numeric($id) && !empty($token)) {
            return $wpdb->get_row($wpdb->prepare("select * from " . $wpdb->prefix . "newsletter where id=%d and token=%s limit 1", $id, $token));
        }

        return null;

        // TODO: Remove!
        if ($this->options_main['wp_integration'] != 1) {
            return null;
        }

        get_currentuserinfo();

        // Retrieve the related newsletter user
        $user = $wpdb->get_row("select * from " . NEWSLETTER_USERS_TABLE . " where wp_user_id=" . $current_user->ID . " limit 1");
        // There is an email matching?
        if (empty($user)) {
            $user = $wpdb->get_row($wpdb->prepare("select * from " . NEWSLETTER_USERS_TABLE . " where email=%s limit 1", strtolower($current_user->user_email)));
            // If not found, create a new Newsletter user, else update the wp_user_id since this email must be linked
            // to the WP user email.
            if (empty($user)) {
                return null;
                //echo 'WP user not found';
                $user = array();
                $user['status'] = 'C';
                $user['wp_user_id'] = $current_user->ID;
                $user['token'] = $this->get_token();
                $user['email'] = strtolower($current_user->user_email);

                $id = $wpdb->insert(NEWSLETTER_USERS_TABLE, $user);
                $user = $newsletter->get_user($id);
            } else {
                //echo 'WP user found via email';
                $wpdb->query($wpdb->prepare("update " . NEWSLETTER_USERS_TABLE . " set wp_user_id=" . $current_user->ID . ", email=%s", $current_user->user_email));
            }
        } else {
            //echo 'WP user found via id';
        }

        return $user;
    }

    function get_form_javascript() {
        $options_profile = get_option('newsletter_profile');
        $buffer .= "\n\n";
        $buffer .= '<script type="text/javascript">' . "\n";
        $buffer .= '//<![CDATA[' . "\n";
        $buffer .= 'if (typeof newsletter_check !== "function") {' . "\n";
        ;
        $buffer .= 'window.newsletter_check = function (f) {' . "\n";
        $buffer .= '    var re = /^([a-zA-Z0-9_\.\-\+])+\@(([a-zA-Z0-9\-]{1,})+\.)+([a-zA-Z0-9]{2,})+$/;' . "\n";
        $buffer .= '    if (!re.test(f.elements["ne"].value)) {' . "\n";
        $buffer .= '        alert("' . addslashes($options_profile['email_error']) . '");' . "\n";
        $buffer .= '        return false;' . "\n";
        $buffer .= '    }' . "\n";
        if ($options_profile['name_status'] == 2 && $options_profile['name_rules'] == 1) {
            $buffer .= '    if (f.elements["nn"] && (f.elements["nn"].value == "" || f.elements["nn"].value == f.elements["nn"].defaultValue)) {' . "\n";
            $buffer .= '        alert("' . addslashes($options_profile['name_error']) . '");' . "\n";
            $buffer .= '        return false;' . "\n";
            $buffer .= '    }' . "\n";
        }
        if ($options_profile['surname_status'] == 2 && $options_profile['surname_rules'] == 1) {
            $buffer .= '    if (f.elements["ns"] && (f.elements["ns"].value == "" || f.elements["ns"].value == f.elements["ns"].defaultValue)) {' . "\n";
            $buffer .= '        alert("' . addslashes($options_profile['surname_error']) . '");' . "\n";
            $buffer .= '        return false;' . "\n";
            $buffer .= '    }' . "\n";
        }
        $buffer .= '    if (f.elements["ny"] && !f.elements["ny"].checked) {' . "\n";
        $buffer .= '        alert("' . addslashes($options_profile['privacy_error']) . '");' . "\n";
        $buffer .= '        return false;' . "\n";
        $buffer .= '    }' . "\n";
        $buffer .= '    return true;' . "\n";
        $buffer .= '}' . "\n";
        $buffer .= '}' . "\n";
        $buffer .= '//]]>' . "\n";
        $buffer .= '</script>' . "\n\n";
        return $buffer;
    }

    /**
     * Returns the form html code for subscription.
     *
     * @return string The html code of the subscription form
     */
    function get_subscription_form($referrer = null, $action=null) {
        $options_profile = get_option('newsletter_profile');
        $options = get_option('newsletter');

        $buffer = $this->get_form_javascript();


        $buffer .= '<div class="newsletter newsletter-subscription">' . "\n";
        if (empty($action)) {
            $buffer .= '<form method="post" action="' . plugins_url('newsletter/do/subscribe.php') . '" onsubmit="return newsletter_check(this)">' . "\n\n";
        } else {
            $buffer .= '<form method="post" action="' . $action . '" onsubmit="return newsletter_check(this)">' . "\n\n";
        }
        if (!empty($referrer)) {
            $buffer .= "<input type='hidden' name='nr' value='$referrer'>\n";
        }
        $buffer .= '<table cellspacing="0" cellpadding="3" border="0">' . "\n\n";
        if ($options_profile['name_status'] == 2) {
            $buffer .= "<!-- first name -->\n";
            $buffer .= "<tr>\n\t" . '<th>' . $options_profile['name'] . '</th>' . "\n\t" . '<td><input class="newsletter-firstname" type="text" name="nn" size="30"' . ($options_profile['name_rules'] == 1 ? 'required' : '') . '></td>' . "\n" . '</tr>' . "\n\n";
        }

        if ($options_profile['surname_status'] == 2) {
            $buffer .= "<!-- last name -->\n";
            $buffer .= "<tr>\n\t" . '<th>' . $options_profile['surname'] . '</th>' . "\n\t" . '<td><input class="newsletter-lastname" type="text" name="ns" size="30"' . ($options_profile['surname_rules'] == 1 ? 'required' : '') . '></td>' . "\n" . '</tr>' . "\n\n";
        }

        $buffer .= "<!-- email -->\n";
        $buffer .= "<tr>\n\t" . '<th>' . $options_profile['email'] . '</th>' . "\n\t" . '<td align="left"><input class="newsletter-email" type="email" name="ne" size="30" required></td>' . "\n" . '</tr>' . "\n\n";

        if ($options_profile['sex_status'] == 2) {
            $buffer .= "<!-- sex -->\n";
            $buffer .= "<tr>\n\t<th>" . $options_profile['sex'] . "</th>\n";
            $buffer .= "\t<td>\n\t" . '<select name="nx" class="newsletter-sex">' . "\n";
            $buffer .= "\t\t" . '<option value="m">' . $options_profile['sex_male'] . '</option>' . "\n";
            $buffer .= "\t\t" . '<option value="f">' . $options_profile['sex_female'] . '</option>' . "\n";
            $buffer .= "\t</select>\n\t</td></tr>\n";
        }

        $lists = '';
        for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
            if ($options_profile['list_' . $i . '_status'] != 2)
                continue;
            $lists .= "\t\t" . '<input type="checkbox" name="nl[]" value="' . $i . '"';
            if ($options_profile['list_' . $i . '_checked'] == 1) $lists .= ' checked';
            $lists .= '/>&nbsp;' . $options_profile['list_' . $i] . '<br />' . "\n";
        }
        if (!empty($lists))
            $buffer .= "<!-- preferences -->\n<tr>\n\t<th>&nbsp;</th>\n\t<td>\n" . $lists . "\t</td>\n</tr>\n\n";

        // Extra profile fields
        for ($i = 1; $i <= NEWSLETTER_PROFILE_MAX; $i++) {
            // Not for subscription form
            if ($options_profile['profile_' . $i . '_status'] != 2)
                continue;

            // Text field
            if ($options_profile['profile_' . $i . '_type'] == 'text') {
                $buffer .= "<tr>\n\t<th>" . $options_profile['profile_' . $i] . "</th>\n\t<td>\n\t\t" . '<input class="newsletter-profile newsletter-profile-' . $i . '" type="text" size="30" name="np' . $i . '"/>' . "\n\t</td>\n</tr>\n\n";
            }

            // Select field
            if ($options_profile['profile_' . $i . '_type'] == 'select') {
                $buffer .= "<tr>\n\t<th>" . $options_profile['profile_' . $i] . "</th>\n\t<td>\n\t\t" . '<select class="newsletter-profile newsletter-profile-' . $i . '" name="np' . $i . '">' . "\n";
                $opts = explode(',', $options_profile['profile_' . $i . '_options']);
                for ($j = 0; $j < count($opts); $j++) {
                    $buffer .= "\t\t\t<option>" . trim($opts[$j]) . "</option>\n";
                }
                $buffer .= "\t\t</select>\n\t</td>\n</tr>\n\n";
            }
        }

        $extra = apply_filters('newsletter_subscription_extra', array());
        foreach ($extra as &$x) {
            $buffer .= "<tr>\n\t<th>" . $x['label'] . "</th>\n\t<td>\n\t\t";
            $buffer .= $x['field'] . "\n\t</td>\n</tr>\n\n";
        }

        if ($options_profile['privacy_status'] == 1) {
            $buffer .= "<tr>\n\t" . '<td colspan="2" class="newsletter-td-privacy">' . "\n";
            $buffer .= "\t\t" . '<input type="checkbox" name="ny" required>&nbsp;';
            if (!empty($options_profile['privacy_url'])) {
                $buffer .= '<a target="_blank" href="' . $options_profile['privacy_url'] . '">';
                $buffer .= $options_profile['privacy'] . '</a>';
            } else {
                $buffer .= $options_profile['privacy'];
            }
            $buffer .= "\n\t</td>\n</tr>\n\n";
        }

        $buffer .= "<tr>\n\t" . '<td colspan="2" class="newsletter-td-submit">' . "\n";

        if (strpos($options_profile['subscribe'], 'http://') !== false) {
            $buffer .= "\t\t" . '<input class="newsletter-submit-image" type="image" src="' . $options_profile['subscribe'] . '"/>' . "\n\t</td>\n</tr>\n\n";
        } else {
            $buffer .= "\t\t" . '<input class="newsletter-submit" type="submit" value="' . $options_profile['subscribe'] . '"/>' . "\n\t</td>\n</tr>\n\n";
        }

        $buffer .= "</table>\n</form>\n</div>";
        return $buffer;
    }

    /**
     * Generate the profile editing form.
     */
    function get_profile_form($user) {
        $options = get_option('newsletter_profile');

        $buffer .= '<div class="newsletter newsletter-profile">';
        $buffer .= '<form action="' . plugins_url('newsletter/do/save.php') . '" method="post">';
        // TODO: use nk
        $buffer .= '<input type="hidden" name="nk" value="' . $user->id . '-' . $user->token . '"/>';
        $buffer .= '<table cellspacing="0" cellpadding="3" border="0">';
        $buffer .= '<tr><th align="right">' . $options['email'] . '</th><td><input class="newsletter-email" type="text" size="30" name="ne" value="' . htmlspecialchars($user->email) . '"/></td></tr>';
        if ($options['name_status'] >= 1) {
            $buffer .= '<tr><th align="right">' . $options['name'] . '</th><td><input class="newsletter-firstname" type="text" size="30" name="nn" value="' . htmlspecialchars($user->name) . '"/></td></tr>';
        }
        if ($options['surname_status'] >= 1) {
            $buffer .= '<tr><th align="right">' . $options['surname'] . '</th><td><input class="newsletter-lastname" type="text" size="30" name="ns" value="' . htmlspecialchars($user->surname) . '"/></td></tr>';
        }
        if ($options['sex_status'] >= 1) {
            $buffer .= '<tr><th align="right">' . $options['sex'] . '</th><td><select name="nx" class="newsletter-sex">';
            //        if (!empty($options['sex_none'])) {
            //            $buffer .= '<option value="n"' . ($user->sex == 'n' ? ' selected' : '') . '>' . $options['sex_none'] . '</option>';
            //        }
            $buffer .= '<option value="f"' . ($user->sex == 'f' ? ' selected' : '') . '>' . $options['sex_female'] . '</option>';
            $buffer .= '<option value="m"' . ($user->sex == 'm' ? ' selected' : '') . '>' . $options['sex_male'] . '</option>';
            $buffer .= '<option value="n"' . ($user->sex == 'n' ? ' selected' : '') . '>' . $options['sex_none'] . '</option>';
            $buffer .= '</select></td></tr>';
        }

        // Profile
        for ($i = 1; $i <= NEWSLETTER_PROFILE_MAX; $i++) {
            if ($options['profile_' . $i . '_status'] == 0)
                continue;

            $buffer .= '<tr><th align="right">' . $options['profile_' . $i] . '</th><td>';
            //if ($options['list_type_' . $i] != 'public') continue;
            $field = 'profile_' . $i;

            if ($options['profile_' . $i . '_type'] == 'text') {
                $buffer .= '<input class="newsletter-profile newsletter-profile-' . $i . '" type="text" size="50" name="np' . $i . '" value="' . htmlspecialchars($user->$field) . '"/>';
            }

            if ($options['profile_' . $i . '_type'] == 'select') {
                $buffer .= '<select class="newsletter-profile newsletter-profile-' . $i . '" name="np' . $i . '">';
                $opts = explode(',', $options['profile_' . $i . '_options']);
                for ($j = 0; $j < count($opts); $j++) {
                    $opts[$j] = trim($opts[$j]);
                    $buffer .= '<option';
                    if ($opts[$j] == $user->$field)
                        $buffer .= ' selected';
                    $buffer .= '>' . $opts[$j] . '</option>';
                }
                $buffer .= '</select>';
            }

            $buffer .= '</td></tr>';
        }

        // Lists
        $buffer .= '<tr><th>&nbsp;</th><td>';
        for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
            if ($options['list_' . $i . '_status'] == 0)
                continue;
            $buffer .= '<input type="checkbox" name="nl[]" value="' . $i . '"';
            $list = 'list_' . $i;
            if ($user->$list == 1)
                $buffer .= ' checked';
            $buffer .= '/> ' . htmlspecialchars($options['list_' . $i]) . '<br />';
        }
        $buffer .= '</td></tr>';

        $extra = apply_filters('newsletter_profile_extra', array(), $user);
        foreach ($extra as &$x) {
            $buffer .= "<tr>\n\t<th>" . $x['label'] . "</th>\n\t<td>\n\t\t";
            $buffer .= $x['field'] . "\n\t</td>\n</tr>\n\n";
        }

        $buffer .= '<tr><td colspan="2" class="newsletter-td-submit">';

        if (strpos($options['save'], 'http://') !== false) {
            $buffer .= '<input class="newsletter-submit" type="image" src="' . $options['save'] . '"/></td></tr>';
        } else {
            $buffer .= '<input class="newsletter-submit" type="submit" value="' . $options['save'] . '"/></td></tr>';
        }

        $buffer .= '</table></form></div>';

        return $buffer;
    }

    function get_form($number) {
        $options = get_option('newsletter_forms');

        $form = $options['form_' . $number];

        if (stripos($form, '<form') === false) {
            $form = '<form method="post" action="' . plugins_url('newsletter/do/subscribe.php') . '">' .
                    $form . '</form>';
        }

        // For compatibility
        $form = str_replace('{newsletter_url}', plugins_url('newsletter/do/subscribe.php'), $form);

        $form = $this->replace_lists($form);

        return $form;
    }

    /** Replaces on passed text the special tag {lists} that can be used to show the preferences as a list of checkbox.
     * They are called lists but on configuration panel they are named preferences!
     *
     * @param string $buffer
     * @return string
     */
    function replace_lists($buffer) {
        $options_profile = get_option('newsletter_profile');
        $lists = '';
        for ($i = 1; $i <= NEWSLETTER_LIST_MAX; $i++) {
            if ($options_profile['list_' . $i . '_status'] != 2)
                continue;
            $lists .= '<input type="checkbox" name="nl[]" value="' . $i . '"/>&nbsp;' . $options_profile['list_' . $i] . '<br />';
        }
        $buffer = str_replace('{lists}', $lists, $buffer);
        $buffer = str_replace('{preferences}', $lists, $buffer);
        return $buffer;
    }

    function notify_admin($user, $subject) {

        if ($this->options['notify'] != 1)
            return;

        $message = "Subscriber details:\n\n" .
                "email: " . $user->email . "\n" .
                "first name: " . $user->name . "\n" .
                "last name: " . $user->surname . "\n" .
                "gender: " . $user->sex . "\n";

        $options_profile = get_option('newsletter_profile');

        for ($i = 0; $i < NEWSLETTER_PROFILE_MAX; $i++) {
            if ($options_profile['profile_' . $i] == '')
                continue;
            $field = 'profile_' . $i;
            $message .= $options_profile['profile_' . $i] . ': ' . $user->$field . "\n";
        }

        for ($i = 0; $i < NEWSLETTER_LIST_MAX; $i++) {
            if ($options_profile['list_' . $i] == '')
                continue;
            $field = 'list_' . $i;
            $message .= $options_profile['list_' . $i] . ': ' . $user->$field . "\n";
        }

        $message .= "token: " . $user->token . "\n" .
                "status: " . $user->status . "\n" .
                "\nYours, Newsletter Pro.";
        $email = trim($this->options['notify_email']);
        if (empty($email))
            $email = get_option('admin_email');
        wp_mail($email, '[' . get_option('blogname') . '] ' . $subject, $message, "Content-type: text/plain; charset=UTF-8\n");
    }

}

NewsletterSubscription::instance();

// TODO: Remove in version 3.5. For compatibility.
add_shortcode('newsletter_embed', 'newsletter_shortcode_form');

add_shortcode('newsletter_form', 'newsletter_shortcode_form');

function newsletter_shortcode_form($attrs, $content) {
    global $cache_stop;
    $cache_stop = true;
    if (isset($attrs['form'])) {
        return NewsletterSubscription::instance()->get_form($attrs['form']);
    } else {
        return NewsletterSubscription::instance()->get_subscription_form();
    }
}

add_shortcode('newsletter', 'newsletter_shortcode');

/**
 *
 * @global type $wpdb
 * @global boolean $cache_stop
 * @global Newsletter $newsletter
 * @param type $attrs
 * @param type $content
 * @return string
 */
function newsletter_shortcode($attrs, $content) {
    global $wpdb, $cache_stop, $newsletter;

    $cache_stop = true;

    $module = NewsletterSubscription::instance();
    $user = $module->get_user_from_request();
    $message_key = $module->get_message_key_from_request();
    $alert = stripslashes($_REQUEST['alert']);

    $message = $module->options[$message_key . '_text'];

    // TODO: the if can be removed
    if ($message_key == 'confirmed') {
        $message .= $module->options[$message_key . '_tracking'];
    }

    // Now check what form must be added
    if ($message_key == 'subscription') {

        // Compatibility check
        if (stripos($message, '<form') !== false) {
            $message .= $module->get_form_javascript();
            $message = str_ireplace('<form', '<form method="post" action="' . plugins_url('newsletter/do/subscribe.php') . '" onsubmit="return newsletter_check(this)"', $message);
        } else {

            if (strpos($message, '{subscription_form') === false) {
                $message .= '{subscription_form}';
            }

            if (isset($attrs['form'])) {
                $message = str_replace('{subscription_form}', $module->get_form($attrs['form']), $message);
            } else {
                $message = str_replace('{subscription_form}', $module->get_subscription_form('page'), $message);
            }
        }
    }

    $message = $newsletter->replace($message, $user, null, 'page');

    if (!empty($alert)) {
        $message .= '<script>alert("' . addslashes(strip_tags($alert)) . '");</script>';
    }

    return $message;
}

// The hook is always active so the module can be activated only on registration (otherwise we should check that
// option on every page load. The registration code should be moved inside the module...
add_action('user_register', 'newsletter_subscription_user_register');

function newsletter_subscription_user_register($wp_user_id) {
    global $wpdb, $newsletter;

    $module = NewsletterSubscription::instance();

    // If the integration is disabled...
    if ($module->options['subscribe_wp_users'] == 0)
        return;

    // If not forced and the user didn't choose the newsletter...
    if ($module->options['subscribe_wp_users'] != 1) {
        if (!isset($_REQUEST['newsletter']))
            return;
    }

    $module->logger->info('Adding a registered WordPress user (' . $wp_user_id . ')');
    $wp_user = $wpdb->get_row($wpdb->prepare("select * from $wpdb->users where id=%d limit 1", $wp_user_id));
    if (empty($wp_user)) {
        $module->logger->error('User not found?!');
        return;
    }

    $_REQUEST['ne'] = $wp_user->user_email;
    $_REQUEST['nr'] = 'registration';
    // Upon registration there is no last name and first name, sorry.
    // With registration always store as unconfirmed, it will be confirmed on first login
    $user = $module->subscribe('S', false);

    // Now we associate it with wp
    $module->set_user_wp_user_id($user->id, $wp_user_id);
}

// Compatibility code

function newsletter_form($number = null) {
    if ($number != null) {
        echo NewsletterSubscription::instance()->get_form($number);
    } else {
        echo NewsletterSubscription::instance()->get_subscription_form();
    }
}

add_action('register_form', 'newsletter_register_form');

function newsletter_register_form() {
    $module = NewsletterSubscription::instance();
    if ($module->options['subscribe_wp_users'] == 2 || $module->options['subscribe_wp_users'] == 3) {
        echo '<p>';
        echo '<input type="checkbox" value="1" name="newsletter"';
        if ($module->options['subscribe_wp_users'] == 3) {
            echo ' checked';
        }
        echo '>&nbsp;';
        echo $module->options['subscribe_wp_users_label'];
        echo '</p>';
    }
}

