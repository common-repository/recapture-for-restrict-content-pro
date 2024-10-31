<?php

class RecaptureRCP extends RecaptureBasePlatform {
    function get_name() {
        return 'rcp';
    }

    public function add_actions() {
        add_action('rcp_transition_membership_status', [$this, 'membership_status_has_changed'], 10, 3);
        add_action('rcp_membership_post_renew', [$this, 'membership_post_renew'], 10, 3);
        add_action('rcp_after_register_form_fields', [$this, 'add_hidden_email_input']);
        add_action('recapture_run_export', [$this, 'run_export']);
        add_action('wp_ajax_recapture_get_level_details', [$this, 'get_subscription_level']);
        add_action('wp_ajax_nopriv_recapture_get_level_details', [$this, 'get_subscription_level']);

        if (get_option('recapture_is_exporting') == true) {
            add_action('admin_notices', [$this, 'exporting_message']);
        }

        add_action('wp', [$this, 'site_loaded']);

        if (isset($_GET['recapture-export-memberships']) && current_user_can('administrator')) {
            check_admin_referer('export_rpc_memberships');
            $this->run_export(true);
            die();
        }
    }

    public function remove_actions() {
        remove_action('wp', [$this, 'site_loaded']);
    }

    public function site_loaded() {
        if (rcp_is_registration_page()) {
            RecaptureUtils::set_cart_id_if_missing();
        }
    }

    public function regenerate_cart_from_url($cart, $contents)
    {
    }

    public static function is_ready() {
        return class_exists('Restrict_Content_Pro');
    }

    /**
     * Checks whether the current user has a subscription or not, the subscription can be
     * any status
     *
     * @return bool
     */

    public function current_user_has_subscription() {
        $customer = rcp_get_customer_by_user_id();

        if (empty($customer)) {
            return false;
        }

        return count($customer->get_memberships()) > 0;
    }

    public function enqueue_scripts() {
        // add JS depending on the page
        if (!rcp_is_registration_page()) {
            return;
        }

        // include js cookie
        wp_enqueue_script(
            'jquery-deparam',
            self::get_base_path().'/js/jquery-deparam.js',
            ['jquery'],
            RECAPTURE_VERSION
        );

        wp_enqueue_script(
            'js-cookie',
            self::get_base_path().'/js/js.cookie-2.2.1.min.js',
            ['jquery'],
            '2.2.1'
        );

        wp_enqueue_script(
            'recapture',
            self::get_base_path().'/js/recapture-rcp.js',
            ['jquery'],
            RECAPTURE_VERSION
        );

        $user = wp_get_current_user();

        wp_localize_script(
            'recapture',
            '__recaptureRcp', [
                'hasSubscription' => $this->current_user_has_subscription(),
                'ajax' => admin_url('admin-ajax.php'),
                'excludeRenewalCarts' => get_option("recapture_rcp_exclude_renewal_carts") == "1",
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'email' => $user->user_email,
            ]
        );
    }

    protected function convert_membership($membership_id) {
        try {
            // get the membership
            $membership = rcp_get_membership($membership_id);

            if ($membership == null) {
                return;
            }

            // get the customer
            $customer = $membership->get_customer();

            if ($customer == null) {
                return;
            }

            // get the user for the email as this is the only way we have to match the record
            $user = get_userdata($customer->get_user_id());

            // get the payments for this user
            $payments = $membership->get_payments();
            $last_payment = end($payments);

            $data = (object) [
                'externalId' => null, //  backend gets the cart from the email address
                'orderId' => $last_payment
                    ? $last_payment->id
                    : null,
                'shippingCountry' => null,
                'billingCountry' => null,
                'email' => $user->user_email,
                'disassociateCart' => true,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
            ];

            // convert the cart in Recapture
            RecaptureUtils::convert_cart($data);

        } catch( Exception $e ) {
        }
    }

    public function membership_post_renew($expiration, $membership_id, $membership) {
        $this->convert_membership($membership_id);
    }

    public function membership_status_has_changed($old_status, $new_status, $membership_id) {
        // we are only interested in active statuses, i.e. they paid/converted
        if ($old_status == $new_status || $new_status !== 'active') {
            return;
        }

        $this->convert_membership($membership_id);
    }

    public function add_hidden_email_input() {

        $current_user = wp_get_current_user();

        $email = $current_user
            ? $current_user->user_email
            : null;

        if (!$email || empty($email)) {
            return;
        }

        ?>
        <input type="hidden" value="<?= esc_attr($email); ?>"/>
        <?php
    }

    public function run_export($forced = false) {
        echo 'Starting export'.PHP_EOL;

        $offset = get_option('recapture_export_offset');
        $offset = $offset == false
            ? 0
            : $offset;

        $page_size = 20;

        $users = get_users([
            'number' => $page_size,
            'offset' => $offset,
        ]);

        // no more to process
        if (count($users) == 0) {
            echo 'Nothing to export, exiting.'.PHP_EOL;
            update_option('recapture_is_exporting', false);
            return;
        }

        echo 'Found '.count($users).' users to export'.PHP_EOL;

        // update the offset
        update_option('recapture_export_offset', $offset + count($users));

        // send to Recapture
        foreach ($users as $user) {

            $customer = rcp_get_customer_by_user_id($user->ID);

            if ($customer == false) {
                continue;
            }

            // get the payments for this user
            $payments = $customer->get_payments([
                'status' => 'complete',
                'order' => 'ASC',
                'orderby' => 'id',
            ]);

            if (count($payments) == 0) {
                echo 'Ignoring '.esc_html($user->user_email).' because there are no payments'.PHP_EOL;
                continue;
            }

            $last_payment = end($payments);
            $first_payment = reset($payments);

            $data = (object) [
                'email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'order_count' => count($payments),
                'last_ordered_at' => $last_payment->date,
                'first_ordered_at' => $first_payment->date
            ];

            echo 'Exporting: '.json_encode($data).PHP_EOL;

            // convert the cart in Recapture
            RecaptureUtils::create_or_update_unique_customer($data);
        }

        // schedule
        if ($forced == false) {
            wp_schedule_single_event(time(), 'recapture_run_export');
        }
    }

    public function exporting_message() {
        $message = sprintf(
            __('Recapture is exporting memberships, this message will disappear when the process is finished', RECAPTURE_TEXT_DOMAIN));

        ?>
            <div class="notice notice-info">
                <p>
                    <?= esc_html($message) ?>
                </p>
            </div> 
        <?php
    }

    public function get_subscription_level() {
        // Ignoring nonce warning because this endpoint can be called from the front end
        // and many sites use caching plugins so any generated nonce will expire causing
        // it to fail

        // phpcs:ignore
        $req = (object) $_POST;
        $level_id = $req->level;

        // get all the levels (support RCP < 3.4)
        $levels = function_exists('rcp_get_membership_levels')
            ? rcp_get_membership_levels()
            : rcp_get_subscription_levels();

        // filter the level we want
        $level = current(array_filter($levels, function($l) use($level_id) {
            return $l->id == $level_id;
        }));

        if (!$level) {
            wp_send_json_success(null);
            return;
        }

        wp_send_json_success([
            'id' => $level->id,
            'price' => $level->price,
            'name' => $level->name,
            'duration' => $level->duration
        ]);
    }
}
