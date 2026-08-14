<?php

declare(strict_types=1);

namespace ProjectSync\Services;

final readonly class ProcessNotificationsCommand
{
    public function __construct(private NotificationProcessor $processor, private int $maximumBatchLimit)
    {
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, output: string, error: string}
     */
    public function run(array $arguments): array
    {
        $limit = min(20, $this->maximumBatchLimit);
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--limit=')) {
                return ['exit_code' => 2, 'output' => '', 'error' => 'Unknown argument.'];
            }
            $value = substr($argument, 8);
            if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $this->maximumBatchLimit) {
                return ['exit_code' => 2, 'output' => '', 'error' => 'Invalid --limit.'];
            }
            $limit = (int) $value;
        }
        $result = $this->processor->process($limit);

        return [
            'exit_code' => $result['failed'] > 0 ? 3 : 0,
            'output' => sprintf('Notifications processed: claimed=%d sent=%d failed=%d recovered=%d', $result['claimed'], $result['sent'], $result['failed'], $result['recovered']),
            'error' => '',
        ];
    }
}
