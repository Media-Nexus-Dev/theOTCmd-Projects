<?php
/**
 * Plugin Name:  theOTCmd BOGO — Buy 1 Get 1 Free
 * Description:  Adds "Buy 1 Get 1 Free (Cheapest Item)" as a native WooCommerce coupon discount type.
 * Version:      2.1.0
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

// 2. Apply discount as a negative fee and store amount for display
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

    sort( $prices );
    $discount = $prices[0];

    // Store so coupon label can display the real amount
    WC()->session->set( 'bogo_discount_amount', $discount );

    $cart->add_fee(
        sprintf( __( '%s: Free Item', 'otcmd-bogo' ), strtoupper( $bogo_coupon->get_code() ) ),
        -$discount,
        wc_tax_enabled()
    );
} );

// 3. Return 0 — fee handles actual discount
add_filter( 'woocommerce_coupon_get_discount_amount', function( $discount, $discounting_amount, $cart_item, $single, WC_Coupon $coupon ) {
    if ( $coupon->get_discount_type() === 'bogo_cheapest_free' ) {
        return 0;
    }
    return $discount;
}, 10, 5 );

// 4. Fix the coupon row HTML to show real discount amount instead of -$0.00
add_filter( 'woocommerce_cart_totals_coupon_html', function( $coupon_html, WC_Coupon $coupon, $discount_amount_html ) {
    if ( $coupon->get_discount_type() !== 'bogo_cheapest_free' ) {
        return $coupon_html;
    }

    $amount = WC()->session ? floatval( WC()->session->get( 'bogo_discount_amount', 0 ) ) : 0;

    if ( $amount <= 0 ) {
        return $coupon_html;
    }

    $remove = sprintf(
        '<a href="%s" class="woocommerce-remove-coupon" data-coupon="%s">%s</a>',
        esc_url( add_query_arg( 'remove_coupon', rawurlencode( $coupon->get_code() ), wc_get_cart_url() ) ),
        esc_attr( $coupon->get_code() ),
        esc_html__( '[Remove]', 'woocommerce' )
    );

    return '-' . wc_price( $amount ) . ' ' . $remove;
}, 10, 3 );

// 5. Friendly notice on apply
add_action( 'woocommerce_applied_coupon', function( string $code ) {
    $coupon = new WC_Coupon( $code );
    if ( $coupon->get_discount_type() !== 'bogo_cheapest_free' ) return;
    wc_add_notice( '🎁 BOGO coupon applied! Your cheapest item will be free at checkout.', 'success' );
} );
