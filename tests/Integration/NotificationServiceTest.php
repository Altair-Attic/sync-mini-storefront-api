<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ProjectSync\Infrastructure\ApplicationBootstrap;
use ProjectSync\Infrastructure\Config;
use ProjectSync\Infrastructure\DatabaseConnection;
use ProjectSync\Infrastructure\Email\EmailDeliveryException;
use ProjectSync\Infrastructure\Email\EmailMessage;
use ProjectSync\Infrastructure\Email\EmailSenderInterface;
use ProjectSync\Infrastructure\Email\FakeEmailSender;
use ProjectSync\Infrastructure\MigrationRunner;
use ProjectSync\Infrastructure\UuidGenerator;
use ProjectSync\Repositories\BusinessProfileRepository;
use ProjectSync\Repositories\NotificationJobRepository;
use ProjectSync\Repositories\OrderItemRepository;
use ProjectSync\Repositories\OrderRepository;
use ProjectSync\Repositories\ProductRepository;
use ProjectSync\Services\CheckoutService;
use ProjectSync\Services\NotificationProcessor;
use ProjectSync\Services\NotificationService;
use ProjectSync\Services\OrderConfirmationTokenService;
use ProjectSync\Services\OrderEmailBuilder;
use ProjectSync\Services\OrderReferenceGenerator;
use ProjectSync\Services\WhatsAppHandoffService;
use Psr\Log\NullLogger;

final class NotificationServiceTest extends TestCase
{
    private PDO $db;
    private string $productId;
    private string $productPublicId;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        ApplicationBootstrap::loadEnvironment($root);
        $database = require $root . '/config/database.php';
        self::assertStringEndsWith('_test', $database['database']);
        $this->db = (new DatabaseConnection(new Config([
            'db.host' => $database['host'], 'db.port' => (string) $database['port'],
            'db.database' => $database['database'], 'db.username' => $database['username'], 'db.password' => $database['password'],
        ])))->connect();
        (new MigrationRunner($this->db, $root . '/database/migrations'))->run();
        $this->db->exec(
            "UPDATE business_profiles SET business_name = 'Notification Store', whatsapp_number = '+2348035732952', "
            . "support_email = 'support@example.com', order_notification_email = NULL, merchant_email_notifications_enabled = TRUE, "
            . "customer_email_notifications_enabled = TRUE, whatsapp_handoff_enabled = TRUE, delivery_enabled = TRUE, pickup_enabled = TRUE, fixed_delivery_fee_kobo = 5000"
        );
        $this->productId = UuidGenerator::v4();
        $this->productPublicId = UuidGenerator::v4();
        $statement = $this->db->prepare("INSERT INTO products (id, public_id, slug, title, price_kobo, is_active, stock_quantity, created_at, updated_at) VALUES (:id, :public_id, :slug, 'Immutable Email Product', 10000, TRUE, 100, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $statement->execute(['id' => $this->productId, 'public_id' => $this->productPublicId, 'slug' => 'notification-test-' . substr($this->productId, 0, 8)]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM notification_jobs WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE \'Notification Test%\')')->execute();
        $this->db->prepare('DELETE FROM order_items WHERE product_id = :id')->execute(['id' => $this->productId]);
        $this->db->prepare("DELETE FROM orders WHERE customer_name LIKE 'Notification Test%'")->execute();
        $this->db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $this->productId]);
    }

    public function testNewOrderSendsUniqueJobsAndReplayHasNoSideEffects(): void
    {
        $sender = new FakeEmailSender();
        $checkout = $this->checkout($sender, true);
        $request = $this->request();

        $created = $checkout->create($request);
        $second = $checkout->create($request);
        $notification = $this->notification($created->order);
        self::assertSame('sent', $notification['merchant_email']);
        self::assertSame('sent', $notification['customer_email']);
        self::assertCount(4, $sender->messages);
        self::assertSame('support@example.com', $sender->messages[0]->recipient);
        self::assertStringContainsString('Immutable Email Product', $sender->messages[0]->body);
        self::assertStringNotContainsString('+2349035732952', $sender->messages[1]->body);
        self::assertCount(4, $sender->messages);
        self::assertSame(4, $this->jobCount());
        self::assertSame(4, $this->sentJobCount());
        $orderId = $this->firstOrderId();
        self::assertFalse((new NotificationJobRepository($this->db))->create(UuidGenerator::v4(), $orderId, 'merchant', str_repeat('a', 64), 5));
        self::assertStringStartsWith('https://wa.me/2348035732952?text=', $this->whatsappUrl($created->order));
        self::assertNotSame($created->order['whatsapp_url'], $second->order['whatsapp_url']);
    }

    public function testEmailFailureQueuesWithoutLosingCommittedOrder(): void
    {
        $sender = new FakeEmailSender(true);
        $checkout = $this->checkout($sender, true);

        $created = $checkout->create($this->request());

        $notification = $this->notification($created->order);
        self::assertSame('queued', $notification['merchant_email']);
        self::assertSame('queued', $notification['customer_email']);
        self::assertSame(1, $this->orderCount());
        $statement = $this->db->query("SELECT status, attempts, last_error_code FROM notification_jobs ORDER BY recipient_type");
        self::assertNotFalse($statement);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertSame('FAKE_DELIVERY_FAILED', $rows[0]['last_error_code']);
        $checkout->create($this->request());
        self::assertSame(4, $sender->attempts);
    }

    public function testDisabledOrUnavailableRecipientsSkipJobs(): void
    {
        $this->db->exec('UPDATE business_profiles SET support_email = NULL, order_notification_email = NULL, customer_email_notifications_enabled = TRUE');
        $request = $this->request();
        $request['customer_email'] = null;

        $created = $this->checkout(new FakeEmailSender(), true)->create($request);

        $notification = $this->notification($created->order);
        self::assertSame('skipped', $notification['merchant_email']);
        self::assertSame('skipped', $notification['customer_email']);
        self::assertSame(0, $this->jobCount());
    }

    public function testDisabledEmailSettingsCreateNoJobsEvenWhenRecipientsExist(): void
    {
        $this->db->exec('UPDATE business_profiles SET merchant_email_notifications_enabled = FALSE, customer_email_notifications_enabled = FALSE');

        $created = $this->checkout(new FakeEmailSender(), true)->create($this->request());

        $notification = $this->notification($created->order);
        self::assertSame('skipped', $notification['merchant_email']);
        self::assertSame('skipped', $notification['customer_email']);
        self::assertSame(0, $this->jobCount());
    }

    public function testClaimsAreAtomicAndSmtpRunsWithoutDatabaseTransaction(): void
    {
        $checkout = $this->checkout(new FakeEmailSender(), false);
        $created = $checkout->create($this->request());
        self::assertSame('queued', $this->notification($created->order)['merchant_email']);
        $id = $this->firstJobId();
        $jobs = new NotificationJobRepository($this->db);
        $firstClaim = $jobs->claim($id);
        self::assertNotNull($firstClaim);
        self::assertNull($jobs->claim($id));
        $this->db->prepare("UPDATE notification_jobs SET status = 'pending', processing_started_at = NULL, available_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $id]);

        $sender = new class ($this->db) implements EmailSenderInterface {
            public bool $called = false;
            public function __construct(private readonly PDO $db) {}
            public function send(EmailMessage $message): void
            {
                if ($this->db->inTransaction()) {
                    throw new EmailDeliveryException('TRANSACTION_OPEN_DURING_SMTP');
                }
                $this->called = true;
            }
        };
        $processor = $this->processor($sender);
        $result = $processor->process(1);
        self::assertTrue($sender->called);
        self::assertSame(1, $result['sent']);
    }

    public function testStaleRecoveryBatchLimitBackoffAndExhaustion(): void
    {
        $checkout = $this->checkout(new FakeEmailSender(), false);
        $checkout->create($this->request());
        $this->db->exec("UPDATE notification_jobs SET status = 'processing', attempts = 1, processing_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
        $processor = $this->processor(new FakeEmailSender(true), 2);

        self::assertSame(300, $processor->delay(1));
        self::assertSame(900, $processor->delay(2));
        self::assertSame(2700, $processor->delay(3));
        self::assertSame(8100, $processor->delay(4));
        $result = $processor->process(1);
        self::assertSame(2, $result['recovered']);
        self::assertSame(1, $result['claimed']);
        self::assertSame(1, $result['failed']);
        self::assertSame(2, $this->pendingJobCount(), 'The failed claim is rescheduled and the second job remains unclaimed.');

        $this->db->exec("UPDATE notification_jobs SET status = 'pending', attempts = max_attempts - 1, available_at = UTC_TIMESTAMP(), processing_started_at = NULL");
        $processor->process(2);
        self::assertSame(2, $this->failedJobCount());
    }

    private function checkout(EmailSenderInterface $sender, bool $immediate): CheckoutService
    {
        $jobs = new NotificationJobRepository($this->db);
        $processor = $this->processor($sender);
        $notifications = new NotificationService(
            new BusinessProfileRepository($this->db), $jobs, $processor, new WhatsAppHandoffService(),
            new NullLogger(), 5, $immediate,
        );

        return new CheckoutService(
            $this->db, new BusinessProfileRepository($this->db), new ProductRepository($this->db),
            new OrderRepository($this->db), new OrderItemRepository($this->db), new OrderReferenceGenerator(),
            new OrderConfirmationTokenService(), 4_294_967_295, $notifications,
        );
    }

    private function processor(EmailSenderInterface $sender, int $timeout = 900): NotificationProcessor
    {
        return new NotificationProcessor(
            new NotificationJobRepository($this->db), new OrderRepository($this->db), new OrderItemRepository($this->db),
            new BusinessProfileRepository($this->db), $sender, new OrderEmailBuilder(), new NullLogger(), 300, $timeout,
        );
    }

    /** @return array{customer_name: string, phone_number: string, customer_email: string|null, fulfilment_method: string, delivery_address: string|null, state: string|null, payment_method: string, items: list<array{product_id: string, quantity: int}>} */
    private function request(): array
    {
        return [
            'customer_name' => 'Notification Test Customer', 'phone_number' => '+2349035732952',
            'customer_email' => 'customer@example.com', 'fulfilment_method' => 'delivery',
            'delivery_address' => '12 Example Street', 'state' => 'Ogun', 'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $this->productPublicId, 'quantity' => 2]],
        ];
    }

    private function firstJobId(): string
    {
        $value = $this->db->query('SELECT id FROM notification_jobs ORDER BY recipient_type LIMIT 1');
        self::assertNotFalse($value);
        $id = $value->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function firstOrderId(): string
    {
        $statement = $this->db->query("SELECT id FROM orders WHERE customer_name LIKE 'Notification Test%' LIMIT 1");
        self::assertNotFalse($statement);
        $id = $statement->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    private function jobCount(): int { return $this->queryCount("SELECT COUNT(*) FROM notification_jobs"); }
    private function sentJobCount(): int { return $this->queryCount("SELECT COUNT(*) FROM notification_jobs WHERE status = 'sent'"); }
    private function pendingJobCount(): int { return $this->queryCount("SELECT COUNT(*) FROM notification_jobs WHERE status = 'pending'"); }
    private function failedJobCount(): int { return $this->queryCount("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed'"); }
    private function orderCount(): int { return $this->queryCount("SELECT COUNT(*) FROM orders WHERE customer_name LIKE 'Notification Test%'"); }
    private function queryCount(string $sql): int
    {
        $statement = $this->db->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function notification(array $order): array
    {
        $notification = $order['notification'] ?? null;
        if (!is_array($notification)) {
            self::fail('Expected notification response state.');
        }
        $result = [];
        foreach ($notification as $key => $value) {
            if (!is_string($key)) {
                self::fail('Expected notification string keys.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $order */
    private function whatsappUrl(array $order): string
    {
        $url = $order['whatsapp_url'] ?? null;
        if (!is_string($url)) {
            self::fail('Expected WhatsApp URL.');
        }

        return $url;
    }
}
