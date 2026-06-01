<?php
/**
 * Plugin Name:  Buy 1 Take 1 for WooCommerce
 * Description:  Adds "Buy 1 Take 1 (Cheapest Item Free)" and "Buy 2 Take 1 (Every 3rd Item Free)" as native WooCommerce coupon discount types. Fully respects product/category restrictions, exclusions, sale item exclusions, expiry dates, usage limits, and min/max spend.
 * Version:      1.0.0
 * Plugin URI:   https://github.com/Media-Nexus-Dev/theOTCmd-Projects
 * Author:       Russel Balmocena
 * Author URI:   https://github.com/Media-Nexus-Dev
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  buy1take1-woocommerce
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── 1. Register both discount types in the WooCommerce coupon dropdown ────────
add_filter( 'woocommerce_coupon_discount_types', function( $types ) {
    $types['b1t1_cheapest_free'] = __( 'Buy 1 Take 1 (Cheapest Item Free)', 'buy1take1-woocommerce' );
    $types['b1t1_buy2_take1']    = __( 'Buy 2 Take 1 (Every 3rd Item Free)', 'buy1take1-woocommerce' );
    return $types;
} );

// ── 2. Shared helper: collect eligible item prices respecting all restrictions ─
if ( ! function_exists( 'b1t1_get_eligible_prices' ) ) :
function b1t1_get_eligible_prices( WC_Cart $cart, WC_Coupon $coupon ): array {
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
endif;

// ── 3. Apply the correct discount as a negative fee ───────────────────────────
add_action( 'woocommerce_cart_calculate_fees', function( WC_Cart $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    static $notice_shown = false;

    $active_coupon = null;
    $active_type   = null;

    foreach ( WC()->cart->get_applied_coupons() as $code ) {
        $coupon = new WC_Coupon( $code );
        $type   = $coupon->get_discount_type();
        if ( in_array( $type, [ 'b1t1_cheapest_free', 'b1t1_buy2_take1' ], true ) ) {
            $active_coupon = $coupon;
            $active_type   = $type;
            break;
        }
    }

    if ( ! $active_coupon ) {
        WC()->session->set( 'b1t1_discount_amount', 0 );
        return;
    }

    $prices  = b1t1_get_eligible_prices( $cart, $active_coupon );
    $min_qty = ( $active_type === 'b1t1_buy2_take1' ) ? 3 : 2;

    if ( count( $prices ) < $min_qty ) {
        WC()->session->set( 'b1t1_discount_amount', 0 );

        if ( ! $notice_shown ) {
            $notice_shown = true;
            $msg = $active_type === 'b1t1_buy2_take1'
                ? __( 'Add at least 3 qualifying products to use this Buy 2 Take 1 offer.', 'buy1take1-woocommerce' )
                : __( 'Add at least 2 qualifying products to use this Buy 1 Take 1 offer.', 'buy1take1-woocommerce' );
            wc_add_notice( $msg, 'notice' );
        }
        return;
    }

    sort( $prices ); // ascending — cheapest first

    if ( $active_type === 'b1t1_cheapest_free' ) {
        // One free item — the cheapest
        $discount = $prices[0];
    } else {
        // Every complete set of 3 → cheapest in each set is free
        $discount = 0;
        $sets     = floor( count( $prices ) / 3 );
        for ( $s = 0; $s < $sets; $s++ ) {
            $discount += $prices[ $s * 3 ];
        }
    }

    WC()->session->set( 'b1t1_discount_amount', $discount );

    $cart->add_fee(
        sprintf( __( '%s: Free Item(s)', 'buy1take1-woocommerce' ), strtoupper( $active_coupon->get_code() ) ),
        -$discount,
        wc_tax_enabled()
    );
} );

// ── 4. Return $0 monetary discount — fee handles it ──────────────────────────
add_filter( 'woocommerce_coupon_get_discount_amount', function( $discount, $discounting_amount, $cart_item, $single, WC_Coupon $coupon ) {
    if ( in_array( $coupon->get_discount_type(), [ 'b1t1_cheapest_free', 'b1t1_buy2_take1' ], true ) ) {
        return 0;
    }
    return $discount;
}, 10, 5 );

// ── 5. Fix coupon row HTML — show real discount instead of -$0.00 ─────────────
add_filter( 'woocommerce_cart_totals_coupon_html', function( $coupon_html, WC_Coupon $coupon, $discount_amount_html ) {
    if ( ! in_array( $coupon->get_discount_type(), [ 'b1t1_cheapest_free', 'b1t1_buy2_take1' ], true ) ) {
        return $coupon_html;
    }

    $amount = WC()->session ? floatval( WC()->session->get( 'b1t1_discount_amount', 0 ) ) : 0;

    $remove = sprintf(
        '<a href="%s" class="woocommerce-remove-coupon" data-coupon="%s">%s</a>',
        esc_url( add_query_arg( 'remove_coupon', rawurlencode( $coupon->get_code() ), wc_get_cart_url() ) ),
        esc_attr( $coupon->get_code() ),
        esc_html__( '[Remove]', 'woocommerce' )
    );

    if ( $amount <= 0 ) {
        return esc_html__( 'Add qualifying items to activate', 'buy1take1-woocommerce' ) . ' ' . $remove;
    }

    return '-' . wc_price( $amount ) . ' ' . $remove;
}, 10, 3 );

// ── 6. Friendly notice when coupon is first applied ───────────────────────────
add_action( 'woocommerce_applied_coupon', function( string $code ) {
    $coupon = new WC_Coupon( $code );
    $type   = $coupon->get_discount_type();

    if ( $type === 'b1t1_cheapest_free' ) {
        wc_add_notice( __( '🎁 Buy 1 Take 1 applied! Add 2 qualifying items — the cheapest one will be free.', 'buy1take1-woocommerce' ), 'success' );
    } elseif ( $type === 'b1t1_buy2_take1' ) {
        wc_add_notice( __( '🎁 Buy 2 Take 1 applied! Add 3 qualifying items — the cheapest will be free.', 'buy1take1-woocommerce' ), 'success' );
    }
} );
