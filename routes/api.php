<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use ProjectSync\Controllers\Admin\AdminPaymentController;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Controllers\Admin\OrderManagementController;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Controllers\CategoryController;
use ProjectSync\Controllers\HealthController;
use ProjectSync\Controllers\OrderConfirmationController;
use ProjectSync\Controllers\OrderController;
use ProjectSync\Controllers\PaymentController;
use ProjectSync\Controllers\PaymentWebhookController;
use ProjectSync\Controllers\ProductController;
use ProjectSync\Controllers\ProductImageController;

return static function (
    HealthController $health,
    AuthController $auth,
    CurrentAdminController $current,
    BusinessProfileController $profile,
    CategoryController $categories,
    ProductController $products,
    ProductImageController $productImages,
    OrderController $orders,
    OrderConfirmationController $confirmations,
    OrderManagementController $orderAdmin,
    PaymentController $payment,
    PaymentWebhookController $webhook,
    AdminPaymentController $paymentAdmin,
): callable {
    return static function (RouteCollector $router) use (
        $health,
        $auth,
        $current,
        $profile,
        $categories,
        $products,
        $productImages,
        $orders,
        $confirmations,
        $orderAdmin,
        $payment,
        $webhook,
        $paymentAdmin,
    ): void {
        $router->addRoute('GET', '/api/v1/health', $health);
        $router->addRoute('GET', '/api/v1/store', [$profile, 'store']);
        $router->addRoute('GET', '/api/v1/categories', [$categories, 'publicList']);
        $router->addRoute('GET', '/api/v1/products', [$products, 'publicList']);
        $router->addRoute('GET', '/api/v1/products/{slug}', [$products, 'publicShow']);
        $router->addRoute('POST', '/api/v1/orders', [$orders, 'create']);
        $router->addRoute('GET', '/api/v1/orders/{reference}/confirmation', [$confirmations, 'show']);
        $router->addRoute('POST', '/api/v1/orders/{reference}/payments', [$payment, 'initialize']);
        $router->addRoute('GET', '/api/v1/orders/{reference}/payments/{paymentReference}', [$payment, 'status']);
        $router->addRoute('POST', '/api/v1/payments/paystack/webhook', [$webhook, 'handle']);
        $router->addRoute('POST', '/api/v1/admin/login', [$auth, 'login']);
        $router->addRoute('POST', '/api/v1/admin/refresh', [$auth, 'refresh']);
        $router->addRoute('GET', '/api/v1/admin/me', [$current, 'me']);
        $router->addRoute('GET', '/api/v1/admin/profile', [$profile, 'admin']);
        $router->addRoute('PUT', '/api/v1/admin/profile', [$profile, 'update']);
        $router->addRoute('GET', '/api/v1/admin/categories', [$categories, 'adminList']);
        $router->addRoute('POST', '/api/v1/admin/categories', [$categories, 'create']);
        $router->addRoute('GET', '/api/v1/admin/categories/{id}', [$categories, 'show']);
        $router->addRoute('PUT', '/api/v1/admin/categories/{id}', [$categories, 'update']);
        $router->addRoute('DELETE', '/api/v1/admin/categories/{id}', [$categories, 'delete']);
        $router->addRoute('GET', '/api/v1/admin/products', [$products, 'adminList']);
        $router->addRoute('POST', '/api/v1/admin/products', [$products, 'create']);
        $router->addRoute('GET', '/api/v1/admin/products/{id}', [$products, 'show']);
        $router->addRoute('PUT', '/api/v1/admin/products/{id}', [$products, 'update']);
        $router->addRoute('PATCH', '/api/v1/admin/products/{id}/availability', [$products, 'updateAvailability']);
        $router->addRoute('DELETE', '/api/v1/admin/products/{id}', [$products, 'delete']);
        $router->addRoute('POST', '/api/v1/admin/products/{id}/image', [$productImages, 'upload']);
        $router->addRoute('GET', '/api/v1/admin/orders', [$orderAdmin, 'list']);
        $router->addRoute('GET', '/api/v1/admin/orders/summary', [$orderAdmin, 'summary']);
        $router->addRoute('GET', '/api/v1/admin/orders/{id}', [$orderAdmin, 'detail']);
        $router->addRoute('PATCH', '/api/v1/admin/orders/{id}/status', [$orderAdmin, 'updateStatus']);
        $router->addRoute('GET', '/api/v1/admin/orders/{orderId}/payments', [$paymentAdmin, 'list']);
        $router->addRoute('POST', '/api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile', [$paymentAdmin, 'reconcile']);
        $router->addRoute('POST', '/api/v1/admin/logout', [$auth, 'logout']);
    };
};
