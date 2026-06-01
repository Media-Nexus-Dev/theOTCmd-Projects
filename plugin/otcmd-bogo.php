<?php
/**
 * Plugin Name:  theOTCmd BOGO — Buy 1 Get 1 Free
 * Description:  Adds "Buy 1 Get 1 Free (Cheapest Item)" as a native WooCommerce coupon discount type.
 * Version:      2.0.0
 * Author:       Russel Balmocena
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Register the custom discount type in the dropdown
add_filter( 'woocommerce_coupon_discount_types', function( $types ) {
    $types['bogo_cheapest_free'] = __( 'Buy 1 Get 1 Free (Cheapest Item)', 'otcmd-bogo' );
    return $types;
} );

// 2. Apply the discount as a negative fee when a BOGO coupon is in the cart
add_action( 'woocommerce_cart_calculate_fees', function( WC_Cart $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    $bogo_coupon = null;

    foreach ( WC()->cart->get_applied_coupons() as $code ) {
        $coupon = new WC_Coupon( $code );
        if ( $coupon->get_discount_type() === 'bogo_cheapest_free' ) {
            $bogo_coupon = $coupon;
            break;
        }
    }

    if ( ! $bogo_coupon ) return;

    // Build flat list of item prices respecting quantity
    $prices = [];
    foreach ( $cart->get_cart() as $item ) {
        $price = floatval( $item['data']->get_price() );
        for ( $i = 0; $i < intval( $item['quantity'] ); $i++ ) {
            $prices[] = $price;
        }
    }

    if ( count( $prices ) < 2 ) {
        wc_add_notice( __( 'Add at least 2 products to qualify for the BOGO offer.', 'otcmd-bogo' ), 'notice' );
        return;
    }

    sort( $prices ); // ascending — cheapest first
    $discount = $prices[0];
    $taxable  = wc_tax_enabled();

    $cart->add_fee(
        sprintf( __( '%s: Free Item', 'otcmd-bogo' ), strtoupper( $bogo_coupon->get_code() ) ),
        -$discount,
        $taxable
    );
} );

// 3. Return 0 monetary discount — our fee handles it
add_filter( 'woocommerce_coupon_get_discount_amount', function( $discount, $discounting_amount, $cart_item, $single, WC_Coupon $coupon ) {
    if ( $coupon->get_discount_type() === 'bogo_cheapest_free' ) {
        return 0;
    }
    return $discount;
}, 10, 5 );

// 4. Friendly notice on coupon apply
add_action( 'woocommerce_applied_coupon', function( string $code ) {
    $coupon = new WC_Coupon( $code );
    if ( $coupon->get_discount_type() !== 'bogo_cheapest_free' ) return;
    wc_add_notice( '🎁 BOGO coupon applied! Your cheapest item will be free at checkout.', 'success' );
} );
