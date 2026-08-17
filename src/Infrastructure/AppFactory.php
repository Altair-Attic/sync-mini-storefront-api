<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure;

use ProjectSync\Controllers\HealthController;
use ProjectSync\Controllers\BusinessProfileController;
use ProjectSync\Controllers\CategoryController;
use ProjectSync\Controllers\ProductController;
use ProjectSync\Controllers\ProductImageController;
use ProjectSync\Controllers\OrderController;
use ProjectSync\Controllers\OrderConfirmationController;
use ProjectSync\Controllers\Admin\AuthController;
use ProjectSync\Controllers\Admin\CurrentAdminController;
use ProjectSync\Controllers\Admin\OrderManagementController;
use ProjectSync\Infrastructure\Auth\JwtService;
use ProjectSync\Infrastructure\Auth\RefreshCookie;
use ProjectSync\Infrastructure\Auth\SameOriginPolicy;
use ProjectSync\Middleware\CorsMiddleware;
use ProjectSync\Middleware\AuthenticationMiddleware;
use ProjectSync\Middleware\RequestIdMiddleware;
use ProjectSync\Repositories\LoginAttemptRepository;
use ProjectSync\Repositories\AdminRefreshTokenRepository;
use ProjectSync\Repositories\RevokedAccessTokenRepository;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\CategoryRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Repositories\MerchantUserRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\OrderStatusHistoryRepository;
use ProjectSync\Services\AuthenticationService;
use ProjectSync\Services\LoginRateLimiter;
use ProjectSync\Services\BusinessProfileService;
use ProjectSync\Services\CategoryService;
use ProjectSync\Services\ProductImageService;
use ProjectSync\Services\ProductService;
use ProjectSync\Services\CheckoutRateLimiter;
use ProjectSync\Services\CheckoutService;
use ProjectSync\Services\OrderManagementService;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\OrderReferenceGenerator;
use ProjectSync\Validators\BusinessProfileValidator;
use ProjectSync\Validators\CategoryValidator;
use ProjectSync\Validators\LoginValidator;
use ProjectSync\Validators\OrderListQueryValidator;
use ProjectSync\Validators\OrderStatusUpdateValidator;
use ProjectSync\Validators\ProductImageValidator;
use ProjectSync\Validators\ProductListQueryValidator;
use ProjectSync\Validators\ProductValidator;
use ProjectSync\Validators\CheckoutValidator;

final class AppFactory
{
    public static function create(string $root): Application
    {
        $app = require $root . '/config/app.php';
        $database = require $root . '/config/database.php';
        $cors = require $root . '/config/cors.php';
        $config = new Config([
            'app.environment' => $app['environment'],
            'app.debug' => $app['debug'],
            'app.log_level' => $app['log_level'],
            'cors.allowed_origins' => $cors['allowed_origins'],
            'db.host' => $database['host'],
            'db.port' => (string) $database['port'],
            'db.database' => $database['database'],
            'db.username' => $database['username'],
            'db.password' => $database['password'],
            'auth.jwt_secret' => $app['authentication']['jwt_secret'],
            'auth.jwt_issuer' => $app['authentication']['jwt_issuer'],
            'auth.jwt_audience' => $app['authentication']['jwt_audience'],
            'auth.jwt_access_ttl_seconds' => (string) $app['authentication']['jwt_access_ttl_seconds'],
            'auth.jwt_clock_skew_seconds' => (string) $app['authentication']['jwt_clock_skew_seconds'],
            'auth.jwt_algorithm' => $app['authentication']['jwt_algorithm'],
            'auth.refresh_token_ttl_seconds' => (string) $app['authentication']['refresh_token_ttl_seconds'],
            'auth.refresh_cookie_name' => $app['authentication']['refresh_cookie_name'],
            'auth.refresh_cookie_path' => $app['authentication']['refresh_cookie_path'],
            'auth.refresh_cookie_secure' => $app['authentication']['refresh_cookie_secure'],
            'auth.refresh_cookie_same_site' => $app['authentication']['refresh_cookie_same_site'],
            'auth.refresh_token_security_secret' => $app['authentication']['refresh_token_security_secret'],
            'auth.application_origin' => $app['authentication']['application_origin'],
            'login.max_attempts' => (string) $app['login']['max_attempts'], 'login.window_seconds' => (string) $app['login']['window_seconds'], 'login.block_seconds' => (string) $app['login']['block_seconds'], 'login.rate_limit_secret' => $app['login']['rate_limit_secret'],
            'product_images.max_bytes' => (string) $app['product_images']['max_bytes'],
            'product_images.max_width' => (string) $app['product_images']['max_width'],
            'product_images.max_height' => (string) $app['product_images']['max_height'],
            'product_images.storage_path' => $app['product_images']['storage_path'],
            'product_images.public_path' => $app['product_images']['public_path'],
            'checkout.security_secret' => $app['checkout']['security_secret'],
            'checkout.max_distinct_items' => (string) $app['checkout']['max_distinct_items'],
            'checkout.max_quantity' => (string) $app['checkout']['max_quantity'],
            'checkout.max_total_kobo' => (string) $app['checkout']['max_total_kobo'],
            'checkout.idempotency_key_max_length' => (string) $app['checkout']['idempotency_key_max_length'],
            'checkout.max_attempts' => (string) $app['checkout']['max_attempts'],
            'checkout.confirmation_max_attempts' => (string) $app['checkout']['confirmation_max_attempts'],
            'checkout.window_seconds' => (string) $app['checkout']['window_seconds'],
            'checkout.block_seconds' => (string) $app['checkout']['block_seconds'],
            'mail.enabled' => $app['mail']['enabled'],
            'mail.host' => $app['mail']['host'],
            'mail.port' => (string) $app['mail']['port'],
            'mail.username' => $app['mail']['username'],
            'mail.password' => $app['mail']['password'],
            'mail.encryption' => $app['mail']['encryption'],
            'mail.from_address' => $app['mail']['from_address'],
            'mail.from_name' => $app['mail']['from_name'],
            'mail.timeout_seconds' => (string) $app['mail']['timeout_seconds'],
            'notifications.max_attempts' => (string) $app['notifications']['max_attempts'],
            'notifications.retry_base_seconds' => (string) $app['notifications']['retry_base_seconds'],
            'notifications.processing_timeout_seconds' => (string) $app['notifications']['processing_timeout_seconds'],
            'notifications.batch_limit' => (string) $app['notifications']['batch_limit'],
            'notifications.security_secret' => $app['notifications']['security_secret'],
        ]);
        $config->allowedString('app.environment', ['local', 'testing', 'staging', 'production']);
        self::validateAuthenticationConfig($config);
        LoggerFactory::assertValidLevel($config->requiredString('app.log_level'));
        (new DatabaseConnection($config))->validate();
        $logger = LoggerFactory::create($root . '/storage/logs/application.log', $app['log_level']);
        $routes = require $root . '/routes/api.php';
        $connection = (new DatabaseConnection($config))->connect();
        $attempts = new LoginAttemptRepository($connection);
        $users = new MerchantUserRepository($connection);
        $refreshTokens = new AdminRefreshTokenRepository($connection);
        $revokedAccessTokens = new RevokedAccessTokenRepository($connection);
        $jwt = new JwtService(
            $config->requiredString('auth.jwt_secret'),
            $config->requiredString('auth.jwt_issuer'),
            $config->requiredString('auth.jwt_audience'),
            (int) $config->requiredString('auth.jwt_access_ttl_seconds'),
            (int) $config->requiredString('auth.jwt_clock_skew_seconds'),
            $config->requiredString('auth.jwt_algorithm'),
        );
        $auth = new AuthenticationService(
            $users,
            $refreshTokens,
            $revokedAccessTokens,
            new LoginRateLimiter($attempts, $config),
            $jwt,
            $config->requiredString('auth.refresh_token_security_secret'),
            (int) $config->requiredString('auth.refresh_token_ttl_seconds'),
            $logger,
        );
        $authenticationMiddleware = new AuthenticationMiddleware($jwt, $users, $revokedAccessTokens);
        $refreshCookie = new RefreshCookie(
            $config->requiredString('auth.refresh_cookie_name'),
            $config->requiredString('auth.refresh_cookie_path'),
            $config->bool('auth.refresh_cookie_secure'),
            $config->requiredString('auth.refresh_cookie_same_site'),
            (int) $config->requiredString('auth.refresh_token_ttl_seconds'),
        );
        $profileController = new BusinessProfileController(
            new BusinessProfileService(new BusinessProfileRepository($connection), new BusinessProfileValidator()),
            $authenticationMiddleware,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );
        $categories = new CategoryRepository($connection);
        $products = new ProductRepository($connection);
        $categoryController = new CategoryController(
            new CategoryService($categories, new CategoryValidator()),
            $authenticationMiddleware,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );
        $productController = new ProductController(
            new ProductService($products, $categories, new ProductValidator(), new ProductListQueryValidator()),
            $authenticationMiddleware,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );
        $imageStoragePath = $config->requiredString('product_images.storage_path');
        if (!self::absolutePath($imageStoragePath)) {
            $imageStoragePath = $root . '/' . ltrim(str_replace('\\', '/', $imageStoragePath), '/');
        }
        $productImageController = new ProductImageController(
            new ProductImageService(
                $products,
                new ProductImageValidator((int) $config->requiredString('product_images.max_bytes')),
                new ProductImageStorage(
                    $imageStoragePath,
                    $config->requiredString('product_images.public_path'),
                    (int) $config->requiredString('product_images.max_width'),
                    (int) $config->requiredString('product_images.max_height'),
                ),
            ),
            $authenticationMiddleware,
            static fn (): array => $_FILES,
        );
        $checkoutValidator = new CheckoutValidator(
            (int) $config->requiredString('checkout.max_distinct_items'),
            (int) $config->requiredString('checkout.max_quantity'),
            (int) $config->requiredString('checkout.idempotency_key_max_length'),
        );
        $orderRepository = new OrderRepository($connection);
        $orderItems = new OrderItemRepository($connection);
        $notificationService = NotificationFactory::service($connection, $config, $logger);
        $checkoutService = new CheckoutService(
            $connection,
            new BusinessProfileRepository($connection),
            $products,
            $orderRepository,
            $orderItems,
            new OrderReferenceGenerator(),
            new OrderConfirmationTokenService($config->requiredString('checkout.security_secret')),
            (int) $config->requiredString('checkout.max_total_kobo'),
            $notificationService,
        );
        $checkoutRateLimiter = new CheckoutRateLimiter($attempts, $config);
        $orderController = new OrderController(
            $checkoutValidator,
            $checkoutService,
            $checkoutRateLimiter,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );
        $confirmationController = new OrderConfirmationController($checkoutService, $checkoutRateLimiter);
        $orderStatusHistory = new OrderStatusHistoryRepository($connection);
        $orderManagementService = new OrderManagementService(
            $connection,
            $orderRepository,
            $orderItems,
            $orderStatusHistory,
            new OrderListQueryValidator(),
            new OrderStatusUpdateValidator(),
            $notificationService,
        );
        $orderManagementController = new OrderManagementController(
            $orderManagementService,
            $authenticationMiddleware,
            static function (): string {
                $body = file_get_contents('php://input');

                return is_string($body) ? $body : '';
            },
        );

        return new Application(
            config: $config,
            logger: $logger,
            routes: $routes(
                new HealthController(),
                new AuthController($auth, new LoginValidator(), $refreshCookie, new SameOriginPolicy($config->requiredString('auth.application_origin')), $authenticationMiddleware, static function (): string { $body = file_get_contents('php://input'); return is_string($body) ? $body : ''; }),
                new CurrentAdminController($authenticationMiddleware),
                $profileController,
                $categoryController,
                $productController,
                $productImageController,
                $orderController,
                $confirmationController,
                $orderManagementController,
            ),
            middleware: [new RequestIdMiddleware(), new CorsMiddleware($config->stringList('cors.allowed_origins'))],
        );
    }

    private static function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function validateAuthenticationConfig(Config $config): void
    {
        $environment = $config->requiredString('app.environment');
        $algorithm = $config->allowedString('auth.jwt_algorithm', ['HS256']);
        $sameSite = $config->allowedString('auth.refresh_cookie_same_site', ['Strict', 'Lax']);
        $jwtSecret = $config->requiredString('auth.jwt_secret');
        $refreshSecret = $config->requiredString('auth.refresh_token_security_secret');
        $accessTtl = (int) $config->requiredString('auth.jwt_access_ttl_seconds');
        $clockSkew = (int) $config->requiredString('auth.jwt_clock_skew_seconds');
        $refreshTtl = (int) $config->requiredString('auth.refresh_token_ttl_seconds');
        $cookieName = $config->requiredString('auth.refresh_cookie_name');
        $cookiePath = $config->requiredString('auth.refresh_cookie_path');
        if ($algorithm !== 'HS256' || $sameSite === '' || $accessTtl < 60 || $accessTtl > 900 || $clockSkew < 0 || $clockSkew > 60 || $refreshTtl < 300) {
            throw new \ProjectSync\Exceptions\ConfigurationException('Authentication lifetime or algorithm configuration is invalid.');
        }
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $cookieName) !== 1
            || !str_starts_with($cookiePath, '/')
            || preg_match('/[;\x00-\x1F\x7F]/', $cookiePath) === 1
        ) {
            throw new \ProjectSync\Exceptions\ConfigurationException('Refresh-cookie name or path is invalid.');
        }
        if (hash_equals($jwtSecret, $refreshSecret)) {
            throw new \ProjectSync\Exceptions\ConfigurationException('JWT and refresh-token secrets must be different.');
        }
        if ($environment === 'production') {
            if (strlen($jwtSecret) < 32 || strlen($refreshSecret) < 32
                || str_contains(strtolower($jwtSecret), 'change-this') || str_contains(strtolower($refreshSecret), 'change-this')
                || !$config->bool('auth.refresh_cookie_secure') || $sameSite !== 'Strict'
            ) {
                throw new \ProjectSync\Exceptions\ConfigurationException('Production authentication secrets and cookie settings are insecure.');
            }
            if (!str_starts_with($config->requiredString('auth.application_origin'), 'https://')) {
                throw new \ProjectSync\Exceptions\ConfigurationException('Production APP_URL must use HTTPS.');
            }
        }
    }
}
