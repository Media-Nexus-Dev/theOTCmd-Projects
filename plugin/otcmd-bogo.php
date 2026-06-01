<?php
/**
 * Plugin Name:  theOTCmd BOGO — Buy 1 Get 1 Free
 * Description:  Adds "Buy 1 Get 1 Free" and "Buy 2 Get 1 Free" as native WooCommerce coupon discount types.
 *               Fully respects product/category restrictions, exclusions, sale item exclusions,
 *               expiry dates, usage limits, and min/max spend.
 * Version:      3.0.0
 * Author:       Russel Balmocena
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── 1. Register both discount types in the dropdown ──────────────────────────
add_filter( 'woocommerce_coupon_discount_types', function( $types ) {
    $types['bogo_cheapest_free'] = __( 'Buy 1 Get 1 Free (Cheapest Item)', 'otcmd-bogo' );
    $types['bogo_buy2_get1']     = __( 'Buy 2 Get 1 Free (Every 3rd Item)', 'otcmd-bogo' );
    return $types;
} );

// ── 2. Shared helper: get eligible item prices for a coupon ──────────────────
function otcmd_get_eligible_prices( WC_Cart $cart, WC_Coupon $coupon ): array {
    $restricted_products   = $coupon->get_product_ids();
    $restricted_categories = $coupon->get_product_categories();
    $excluded_products     = $coupon->get_excluded_product_ids();
    $excluded_categories   = $coupon->get_excluded_product_categories();
    $exclude_sale_items    = $coupon->get_exclude_sale_items();
    $has_include           = ! empty( $restricted_products ) || ! empty( $restricted_categories );

    $prices = [];

    foreach ( $cart->get_cart() as $item ) {
        $product      = $item['data'];
        $product_id   = (int) $item['product_id'];
        $variation_id = (int) ( $item['variation_id'] ?? 0 );
        $price        = floatval( $product->get_price() );
        $qty          = intval( $item['quantity'] );
        $cat_ids      = wc_get_product_cat_ids( $product_id );

        // Skip excluded products
        if ( in_array( $product_id, $excluded_products, true ) ||
             in_array( $variation_id, $excluded_products, true ) ) {
            continue;
        }

        // Skip excluded categories
        if ( ! empty( $excluded_categories ) &&
             array_intersect( $excluded_categories, $cat_ids ) ) {
            continue;
        }

        // Skip sale items if coupon says so
        if ( $exclude_sale_items && $product->is_on_sale() ) {
            continue;
        }

        // If restrictions exist, only include matching products/categories
        if ( $has_include ) {
            $included = false;

            if ( ! empty( $restricted_products ) &&
                 ( in_array( $product_id, $restricted_products, true ) ||
                   in_array( $variation_id, $restricted_products, true ) ) ) {
                $included = true;
            }

            if ( ! $included && ! empty( $restricted_categories ) &&
                 array_intersect( $restricted_categories, $cat_ids ) ) {
                $included = true;
            }

            if ( ! $included ) continue;
        }

        for ( $i = 0; $i < $qty; $i++ ) {
            $prices[] = $price;
        }
    }

    return $prices;
}

// ── 3. Apply the correct discount based on type ──────────────────────────────
add_action( 'woocommerce_cart_calculate_fees', function( WC_Cart $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    static $notice_shown = false;

    $bogo_coupon = null;
    $bogo_type   = null;

    foreach ( WC()->cart->get_applied_coupons() as $code ) {
        $coupon = new WC_Coupon( $code );
        $type   = $coupon->get_discount_type();
        if ( in_array( $type, [ 'bogo_cheapest_free', 'bogo_buy2_get1' ], true ) ) {
            $bogo_coupon = $coupon;
            $bogo_type   = $type;
            break;
        }
    }

    if ( ! $bogo_coupon ) {
        WC()->session->set( 'bogo_discount_amount', 0 );
        return;
    }

    $prices  = otcmd_get_eligible_prices( $cart, $bogo_coupon );
    $min_qty = ( $bogo_type === 'bogo_buy2_get1' ) ? 3 : 2;

    if ( count( $prices ) < $min_qty ) {
        WC()->session->set( 'bogo_discount_amount', 0 );

        if ( ! $notice_shown ) {
            $notice_shown = true;
            $msg = $bogo_type === 'bogo_buy2_get1'
                ? __( 'Add at least 3 qualifying products to use this Buy 2 Get 1 offer.', 'otcmd-bogo' )
                : __( 'Add at least 2 qualifying products to use this BOGO offer.', 'otcmd-bogo' );
            wc_add_notice( $msg, 'notice' );
        }
        return;
    }

    sort( $prices ); // ascending — cheapest first

    if ( $bogo_type === 'bogo_cheapest_free' ) {
        $discount = $prices[0];
    } else {
        // Every complete set of 3 → cheapest in that set is free
        $discount = 0;
        $sets     = floor( count( $prices ) / 3 );
        for ( $s = 0; $s < $sets; $s++ ) {
            $discount += $prices[ $s * 3 ];
        }
    }

    WC()->session->set( 'bogo_discount_amount', $discount );

    $cart->add_fee(
        sprintf( __( '%s: Free Item(s)', 'otcmd-bogo' ), strtoupper( $bogo_coupon->get_code() ) ),
        -$discount,
        wc_tax_enabled()
    );
} );

// ── 4. Return 0 monetary discount — fee handles it ───────────────────────────
add_filter( 'woocommerce_coupon_get_discount_amount', function( $discount, $discounting_amount, $cart_item, $single, WC_Coupon $coupon ) {
    if ( in_array( $coupon->get_discount_type(), [ 'bogo_cheapest_free', 'bogo_buy2_get1' ], true ) ) {
        return 0;
    }
    return $discount;
}, 10, 5 );

// ── 5. Fix coupon row HTML to show real amount instead of -$0.00 ─────────────
add_filter( 'woocommerce_cart_totals_coupon_html', function( $coupon_html, WC_Coupon $coupon, $discount_amount_html ) {
    if ( ! in_array( $coupon->get_discount_type(), [ 'bogo_cheapest_free', 'bogo_buy2_get1' ], true ) ) {
        return $coupon_html;
    }

    $amount = WC()->session ? floatval( WC()->session->get( 'bogo_discount_amount', 0 ) ) : 0;

    $remove = sprintf(
        '<a href="%s" class="woocommerce-remove-coupon" data-coupon="%s">%s</a>',
        esc_url( add_query_arg( 'remove_coupon', rawurlencode( $coupon->get_code() ), wc_get_cart_url() ) ),
        esc_attr( $coupon->get_code() ),
        esc_html__( '[Remove]', 'woocommerce' )
    );

    if ( $amount <= 0 ) {
        return __( 'Add qualifying items to activate', 'otcmd-bogo' ) . ' ' . $remove;
    }

    return '-' . wc_price( $amount ) . ' ' . $remove;
}, 10, 3 );

// ── 6. Friendly notice on coupon apply ───────────────────────────────────────
add_action( 'woocommerce_applied_coupon', function( string $code ) {
    $coupon = new WC_Coupon( $code );
    $type   = $coupon->get_discount_type();

    if ( $type === 'bogo_cheapest_free' ) {
        wc_add_notice( '🎁 BOGO applied! Add 2 qualifying items — the cheapest one will be free.', 'success' );
    } elseif ( $type === 'bogo_buy2_get1' ) {
        wc_add_notice( '🎁 Buy 2 Get 1 applied! Add 3 qualifying items — the cheapest will be free.', 'success' );
    }
} );
