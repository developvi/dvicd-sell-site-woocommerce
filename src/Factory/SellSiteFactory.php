<?php 
 namespace DVICD\Factory;

 use DVICD\Controllers\CartController;
 use DVICD\Controllers\CheckoutController;
 use DVICD\Controllers\OrderController;
 use DVICD\Controllers\ProductController;
 use DVICD\Controllers\SiteController;
 use DVICD\Controllers\SubscriptionStatusController;
use DVICD\Providers\SellSite;

 class SellSiteFactory {
    private $instances;

    public function __construct() {
        $this->instances = [
            'cart' => new CartController(),
            'checkout' => new CheckoutController(),
            'order' => new OrderController(),
            'product' => new ProductController(),
            'site' => new SiteController(),
            'subscription' => new SubscriptionStatusController(),
        ];
    }
 
    public function createObject() {
        return new SellSite($this->instances);
    }

 }