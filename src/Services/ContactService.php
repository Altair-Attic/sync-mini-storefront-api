<?php

declare(strict_types=1);

namespace ProjectSync\Services;

use ProjectSync\Infrastructure\Email\EmailDeliveryException;
use ProjectSync\Infrastructure\Email\EmailMessage;
use ProjectSync\Infrastructure\Email\EmailSenderInterface;
use ProjectSync\Repositories\BusinessProfileRepository;
use Psr\Log\LoggerInterface;

final readonly class ContactService
{
    public function __construct(private BusinessProfileRepository $profiles, private EmailSenderInterface $email, private LoggerInterface $logger)
    {
    }

    /**
     * @param array{name: string, email: string, message: string} $contact
     * @return array{email_status: string, whatsapp_url: string|null}
     */
    public function send(array $contact, string $requestId): array
    {
        $profile = $this->profiles->findProfile();
        if ($profile === null) throw new \ProjectSync\Exceptions\BusinessProfileNotFoundException();
        $recipient = $profile['support_email'];
        $emailStatus = 'unavailable';
        if (is_string($recipient) && $recipient !== '') {
            try {
                $this->email->send(new EmailMessage($recipient, 'New contact message for ' . $profile['business_name'], implode("\n", [
                    'Name: ' . $contact['name'], 'Email: ' . $contact['email'], '', 'Message:', $contact['message'],
                ])));
                $emailStatus = 'sent';
            } catch (EmailDeliveryException $exception) {
                $this->logger->warning('Contact email delivery failed.', ['request_id' => $requestId, 'reason' => $exception->getMessage()]);
            }
        }
        $digits = preg_replace('/\D+/', '', $profile['whatsapp_number']);
        $whatsAppUrl = is_string($digits) && preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1
            ? 'https://wa.me/' . $digits . '?text=' . rawurlencode(implode("\n", ['Hello ' . $profile['business_name'] . ',', 'Name: ' . $contact['name'], 'Email: ' . $contact['email'], '', $contact['message']]))
            : null;

        return ['email_status' => $emailStatus, 'whatsapp_url' => $whatsAppUrl];
    }
}
