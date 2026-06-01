<?php
/**
 * Plugin Name:  theOTCmd BOGO — Buy 1 Get 1 Free
 * Description:  Apply coupon code BOGO-OTC to get the cheapest item in the cart for free.
 * Version:      1.0.0
 * Author:       Russel Balmocena
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OTCmd_BOGO {

    const COUPON_CODE = 'bogo-otc';

    public function __construct() {
        // Register the virtual coupon so WooCommerce accepts it at checkout
        add_filter( 'woocommerce_get_shop_coupon_data', [ $this, 'register_virtual_coupon' ], 10, 2 );

        // Apply the discount as a negative fee when the coupon is in the cart
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_bogo_discount' ] );

        // Show a friendly notice when the coupon is applied
        add_action( 'woocommerce_applied_coupon', [ $this, 'applied_notice' ] );
    }

    /**
     * Register BOGO-OTC as a virtual WooCommerce coupon.
     * This means we don't need to create it manually in the WP dashboard.
     */
    public function register_virtual_coupon( $data, $code ) {
        if ( strtolower( $code ) !== self::COUPON_CODE ) {
            return $data;
        }

        return [
            'id'                         => 999901,
            'code'                       => self::COUPON_CODE,
            'amount'                     => 0,           // actual discount handled via fee
            'discount_type'              => 'fixed_cart',
            'individual_use'             => false,
            'usage_limit'                => '',
            'usage_limit_per_user'       => 1,           // one use per customer account
            'usage_count'                => 0,
            'date_expires'               => null,
            'free_shipping'              => false,
            'minimum_amount'             => '',
            'maximum_amount'             => '',
            'product_ids'                => [],
            'excluded_product_ids'       => [],
            'product_categories'         => [],
            'excluded_product_categories'=> [],
            'exclude_sale_items'         => false,
        ];
    }

    /**
     * When BOGO-OTC coupon is in the cart, find the cheapest item
     * and apply its full price as a negative fee (i.e. 100% discount on it).
     */
    public function apply_bogo_discount( WC_Cart $cart ) {
        if ( ! $this->coupon_is_applied() ) {
            return;
        }

        $item_prices = $this->get_item_prices( $cart );

        // Need at least 2 items for BOGO to apply
        if ( count( $item_prices ) < 2 ) {
            wc_add_notice(
                __( 'Add at least 2 products to use the BOGO-OTC offer.', 'otcmd-bogo' ),
                'notice'
            );
            return;
        }

        // Sort ascending — lowest price first
        sort( $item_prices );

        $cheapest = $item_prices[0];

        // Remove any previously added BOGO fee to avoid doubling on recalc
        $this->remove_existing_fee( $cart );

        // Add negative fee = free item
        $cart->add_fee(
            __( 'BOGO-OTC: Free Item Discount', 'otcmd-bogo' ),
            -$cheapest,
            true  // taxable — mirrors the original item's tax treatment
        );
    }

    /**
     * Build a flat list of individual item prices, respecting quantities.
     * e.g. 2x $30 item → [ 30, 30 ]
     */
    private function get_item_prices( WC_Cart $cart ): array {
        $prices = [];

        foreach ( $cart->get_cart() as $item ) {
            $price    = floatval( $item['data']->get_price() );
            $quantity = intval( $item['quantity'] );

            for ( $i = 0; $i < $quantity; $i++ ) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * Remove the BOGO fee if it already exists (prevents stacking on cart updates).
     */
    private function remove_existing_fee( WC_Cart $cart ): void {
        $fees = $cart->get_fees();
        foreach ( $fees as $key => $fee ) {
            if ( strpos( $fee->name, 'BOGO-OTC' ) !== false ) {
                unset( $cart->fees_api()->get_fees()[ $key ] );
            }
        }
    }

    /**
     * Check if the BOGO coupon is currently applied to the cart.
     */
    private function coupon_is_applied(): bool {
        return WC()->cart && in_array(
            self::COUPON_CODE,
            array_map( 'strtolower', WC()->cart->get_applied_coupons() ),
            true
        );
    }

    /**
     * Friendly notice when coupon is first applied.
     */
    public function applied_notice( string $code ): void {
        if ( strtolower( $code ) !== self::COUPON_CODE ) {
            return;
        }
        wc_add_notice(
            __( '🎁 BOGO-OTC applied! Your cheapest item will be FREE at checkout.', 'otcmd-bogo' ),
            'success'
        );
    }
}

new OTCmd_BOGO();
