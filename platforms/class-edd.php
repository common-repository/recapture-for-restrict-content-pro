<?php

/*
 * Easy-Digital-Downloads calls empty_cart() before orders are processed so
 * we have to be careful about how we send carts to Recapture. So we Reset
 * the cart ID on the success page so the empty cart is a new cart id
 *
 * Another issue is how carts are converted. The edd_complete_purchase action
 * is often not called in the contact of the user request, it might be called
 * from a webhook, in which case we don't have the cart ID available. So we
 * send the email address and use that to match the cart
 *
 */

class RecaptureEDD extends RecaptureBasePlatform {
    function get_name() {
        return 'edd';
    }

    public function add_actions() {
        add_action('edd_post_add_to_cart', [$this, 'update_cart']);
        add_action('edd_post_remove_from_cart', [$this, 'update_cart']);
        add_action('edd_complete_purchase', [$this, 'order_completed']);
        add_action('wp', [$this, 'site_loaded']);

        add_action('edd_straight_to_gateway_purchase_data', [$this, 'straight_to_gateway']);
    }

    public function remove_actions() {
        remove_action('edd_post_add_to_cart', [$this, 'update_cart']);
        remove_action('edd_post_remove_from_cart', [$this, 'update_cart']);
        remove_action('edd_complete_purchase', [$this, 'order_completed']);
        remove_action('wp', [$this, 'site_loaded']);
    }

    public function site_loaded() {
        // set a new checkout id on the thank you page the payment
        // success will be handled by email address only
        if (edd_is_success_page()) {
            RecaptureUtils::set_new_cart_id();
        }
    }

    protected function set_recapture_cart($items) {
        $cart_id = RecaptureUtils::get_cart_id();
        $cart = (object) [
            'externalId' => $cart_id,
            'products' => [],
            'checkoutUrl' => null
        ];

        $cart_items = [];

        foreach ($items as $item_key => $item) {

            $quantity = max($item['quantity'], 1);
            $price_per_item = $item['price'] / $quantity;
            $price = number_format($price_per_item , 2, '.', '');
            $image_url = wp_get_attachment_url(get_post_thumbnail_id($item['id']));

            $variant_id = isset($item['item_number']['options']['price_id'])
                ? $item['item_number']['options']['price_id']
                : null;

            $cart->products[] = [
                'name' => $item['name'],
                'imageUrl' => $image_url ? $image_url : '',
                'url' => get_permalink($item['id']),
                'price' => strval($price),
                'externalId' => $item['id'],
                'quantity' => $quantity,
                'variant_id' => $variant_id,
            ];

            $cart_items[] = $item['id'].'|'.$variant_id.'|'.$item['quantity'];
        }

        $cart_contents = RecaptureUtils::encode_array($cart_items);
        $cart->checkoutUrl = edd_get_checkout_uri().'?racart='.$cart_id.'&contents='.$cart_contents;

        RecaptureUtils::send_cart($cart);
    }

    public function straight_to_gateway($purchase_data) {
        $this->set_recapture_cart($purchase_data['cart_details']);
        return $purchase_data;
    }

    public function update_cart() {
        $items = edd_get_cart_content_details();
        $this->set_recapture_cart($items);
    }

    public function order_completed($payment_id)
    {
        $meta = get_post_meta($payment_id, '_edd_payment_meta', true);
        $user_info = $meta['user_info'];
        $address = $user_info['address'];
        $country = $address['country'];

        $data = (object) [
            'externalId' => null, //  backend gets the cart from the email address
            'orderId' => $payment_id,
            'shippingCountry' => $country,
            'billingCountry' => $country,
            'email' => edd_get_payment_user_email($payment_id)
        ];

        // convert the cart
        RecaptureUtils::convert_cart($data);

        // set a new cart id
        RecaptureUtils::set_new_cart_id();
    }

    public function regenerate_cart_from_url($cart, $contents)
    {
        // get the cart contents
        $contents = RecaptureUtils::decode_array($contents);

        // empty the current cart - if any
        $this->remove_actions();

        // Clear any old cart_contents
        edd_empty_cart();

        foreach ($contents as $item) {
            $parts = explode('|', $item);

            $download_id = $parts[0];
            $options = isset($parts[1])
                ? [
                    'price_id' => $parts[1],
                    'quantity' =>$parts[2]
                ]
                : ['quantity' => $parts[2]];

            edd_add_to_cart($download_id, $options);
        }

        $this->add_actions();
    }

    function is_product_page() {
        return is_singular('download');
    }

    public static function is_ready() {
        return class_exists('Easy_Digital_Downloads');
    }

    public function enqueue_scripts() {
    }
}
