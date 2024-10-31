(function () {
  function debounce(timeout, callback) {
    let bounceTimeout = null;

    return function () {
      if (bounceTimeout) {
        clearTimeout(bounceTimeout);
      }
      bounceTimeout = setTimeout(callback, timeout);
    };
  }

  function getCartUrl(level, cartId) {
    // parse current params (if any)
    const params = jQuery.deparam(window.location.search.substring(1));

    // set the level
    params.level = level;

    // remove the discount (if any) so it can be added by recapture
    if (params.discount) {
      delete params.discount;
    }

    // set the cart id
    params.racart = cartId;

    // return the new url
    return (
      location.protocol +
      '//' +
      location.host +
      location.pathname +
      '?' +
      jQuery.param(params)
    );
  }

  function getProductName(level) {
    return new Promise(resolve => {
      jQuery.ajax({
        type: 'post',
        dataType: 'json',
        url: rcp_script_options.ajaxurl,
        data: {
          action: 'recapture_get_level_details',
          level,
        },
        success: function (response) {
          if (!response.success) {
            resolve('');
            return;
          }

          resolve(response?.data?.name || '');
        },
        error: function () {
          resolve('');
        },
      });
    });
  }

  function getProductInfo(onSuccess, onError) {
    const state = rcp_get_registration_form_state();

    jQuery.ajax({
      type: 'post',
      dataType: 'json',
      url: rcp_script_options.ajaxurl,
      data: {
        action: 'rcp_validate_registration_state',
        rcp_level: state.membership_level,
        lifetime: state.lifetime,
        level_has_trial: state.level_has_trial,
        is_free: state.is_free,
        discount_code: state.discount_code,
        rcp_gateway: state.gateway,
        rcp_auto_renew: true === state.auto_renew ? true : '',
        event_type: null,
        registration_type: jQuery('#rcp-registration-type').val(),
        membership_id: jQuery('#rcp-membership-id').val(),
        rcp_registration_payment_id: jQuery('#rcp-payment-id').val(),
      },
      success: async function (response) {
        if (!response.success) {
          return;
        }

        const {data} = response;

        const name =
          // get form the api first
          (await getProductName(data.level_id)) ||
          // Get the subscription level name using the selector
          jQuery(
            `.rcp_subscription_level_${data.level_id} .rcp_subscription_level_name`,
          ).text() ||
          // Use the totals table
          jQuery(
            '.rcp_registration_total_details td[data-th=Membership]',
          ).text();

        onSuccess({
          level: data.level_id,
          price: data.initial_total,
          lifetime: data.lifetime,
          discountCode: data.discount_code,
          gateway: data.gateway,
          gatewayData: rcp_get_gateway(),
          autoRenew: jQuery('#rcp_auto_renew').prop('checked'),
          name,
        });
      },
      error: function (xhr, options, err) {
        onError(err);
      },
    });
  }

  const sendCartToRecapture = debounce(2500, function () {
    // If we have a subscription and we should not create carts for renewals then exit
    if (__recaptureRcp.hasSubscription && __recaptureRcp.excludeRenewalCarts) {
      return;
    }

    getProductInfo(
      function (product) {
        const cartId = Cookies.get('racart');

        const cart = {
          externalId: cartId,
          checkoutUrl: getCartUrl(product.level, cartId),
          customerId: Cookies.get('ra_customer_id'),
          firstName:
            jQuery('#rcp_user_first').val() || __recaptureRcp.firstName,
          lastName: jQuery('#rcp_user_last').val() || __recaptureRcp.lastName,
          email: jQuery('#rcp_user_email').val() || __recaptureRcp.email,
          products: [
            {
              externalId: product.level,
              sku: product.level,
              name: product.name,
              price: product.price,
              imageUrl: '',
              quantity: 1,
            },
          ],
          shipping: 0,
          tax: 0,
        };

        ra('setCart', [cart]);
      },
      function (err) {
        // Level not found in RCP, don't log the cart
      },
    );
  });

  function onDocumentKeyPress() {
    // send the cart to Recapture
    sendCartToRecapture();
  }

  function onLevelChange(level) {
    sendCartToRecapture();
  }

  function onDiscountChanged() {
    sendCartToRecapture();
  }

  function onDiscountApplies(discount, d2) {
    sendCartToRecapture();
  }

  function onLoad() {
    // make sure we have valid data from WP
    if (!__recaptureRcp) {
      return;
    }

    // handle change level events
    jQuery('body').on('rcp_level_change', onLevelChange);
    jQuery('body').on('rcp_discount_change', onDiscountChanged);
    jQuery('body').on('rcp_discount_applied', onDiscountApplies);

    if (__recaptureRcp.email && ra) {
      ra('email');
    }

    if (__recaptureRcp.hasSubscription) {
      // The user already has a subscription to create the cart
      sendCartToRecapture();
    } else {
      // wait for a keypress, then send the cart
      jQuery(document).on('keypress.recapture', onDocumentKeyPress);
    }
  }

  jQuery(document).ready(onLoad);
})();
