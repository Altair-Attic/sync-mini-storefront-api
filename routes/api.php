<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use ProjectSync\Controllers\Admin\AdminPaymentController;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Controllers\Admin\OrderManagementController;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Controllers\CategoryController;
use ProjectSync\Controllers\DocumentationController;
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
    DocumentationController $docs,
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
        $docs,
    ): void {
        // Documentation & OpenAPI specification routes
        $router->addRoute('GET', '/api/docs', [$docs, 'ui']);
        $router->addRoute('GET', '/api/v1/docs', [$docs, 'ui']);
        $router->addRoute('GET', '/api/openapi.yaml', [$docs, 'yaml']);
        $router->addRoute('GET', '/api/v1/openapi.yaml', [$docs, 'yaml']);
        $router->addRoute('GET', '/api/openapi.json', [$docs, 'json']);
        $router->addRoute('GET', '/api/v1/openapi.json', [$docs, 'json']);

        // System
        $router->addRoute('GET', '/api/v1/health', $health);
        $router->addRoute('GET', '/api/v1/health/ready', [$health, 'readiness']);

        // Store & Catalogue (Public)
        $router->addRoute('GET', '/api/v1/store', [$profile, 'store']);
        $router->addRoute('GET', '/api/v1/categories', [$categories, 'publicList']);
        $router->addRoute('GET', '/api/v1/products', [$products, 'publicList']);
        $router->addRoute('GET', '/api/v1/products/{slug}', [$products, 'publicShow']);

        // Orders & Confirmation (Guest)
        $router->addRoute('POST', '/api/v1/orders', [$orders, 'create']);
        $router->addRoute('GET', '/api/v1/orders/{reference}/confirmation', [$confirmations, 'show']);

        // Payments & Webhooks
        $router->addRoute('POST', '/api/v1/orders/{reference}/payments', [$payment, 'initialize']);
        $router->addRoute('GET', '/api/v1/orders/{reference}/payments/{paymentReference}', [$payment, 'status']);
        $router->addRoute('POST', '/api/v1/payments/paystack/webhook', [$webhook, 'handle']);

        // Admin Authentication
        $router->addRoute('POST', '/api/v1/admin/login', [$auth, 'login']);
        $router->addRoute('POST', '/api/v1/admin/logout', [$auth, 'logout']);
        $router->addRoute('GET', '/api/v1/admin/me', [$current, 'me']);

        // Admin Business Profile
        $router->addRoute('GET', '/api/v1/admin/profile', [$profile, 'admin']);
        $router->addRoute('PUT', '/api/v1/admin/profile', [$profile, 'update']);

        // Admin Categories
        $router->addRoute('GET', '/api/v1/admin/categories', [$categories, 'adminList']);
        $router->addRoute('POST', '/api/v1/admin/categories', [$categories, 'create']);
        $router->addRoute('GET', '/api/v1/admin/categories/{id}', [$categories, 'show']);
        $router->addRoute('PUT', '/api/v1/admin/categories/{id}', [$categories, 'update']);
        $router->addRoute('DELETE', '/api/v1/admin/categories/{id}', [$categories, 'delete']);

        // Admin Products
        $router->addRoute('GET', '/api/v1/admin/products', [$products, 'adminList']);
        $router->addRoute('POST', '/api/v1/admin/products', [$products, 'create']);
        $router->addRoute('GET', '/api/v1/admin/products/{id}', [$products, 'show']);
        $router->addRoute('PUT', '/api/v1/admin/products/{id}', [$products, 'update']);
        $router->addRoute('PATCH', '/api/v1/admin/products/{id}/availability', [$products, 'updateAvailability']);
        $router->addRoute('DELETE', '/api/v1/admin/products/{id}', [$products, 'delete']);
        $router->addRoute('POST', '/api/v1/admin/products/{id}/image', [$productImages, 'upload']);

        // Admin Orders & Fulfilment
        $router->addRoute('GET', '/api/v1/admin/orders', [$orderAdmin, 'list']);
        $router->addRoute('GET', '/api/v1/admin/orders/summary', [$orderAdmin, 'summary']);
        $router->addRoute('GET', '/api/v1/admin/orders/{id}', [$orderAdmin, 'detail']);
        $router->addRoute('PATCH', '/api/v1/admin/orders/{id}/status', [$orderAdmin, 'updateStatus']);

        // Admin Payments & S2S Reconciliation
        $router->addRoute('GET', '/api/v1/admin/orders/{orderId}/payments', [$paymentAdmin, 'list']);
        $router->addRoute('POST', '/api/v1/admin/orders/{orderId}/payments/{paymentId}/reconcile', [$paymentAdmin, 'reconcile']);
    };
};
