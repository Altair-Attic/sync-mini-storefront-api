<?php

declare(strict_types=1);

namespace ProjectSync\Validators;

use DateTimeImmutable;
use ProjectSync\Exceptions\ValidationException;

final class OrderListQueryValidator
{
    /** @var list<string> */
    public const array ALLOWED_STATUSES = [
        'new',
        'confirmed',
        'processing',
        'ready',
        'completed',
        'cancelled',
    ];

    /** @var list<string> */
    public const array ALLOWED_SORTS = [
        'newest',
        'oldest',
        'total_high',
        'total_low',
    ];

    /**
     * @param array<string, mixed> $query
     * @return array{page: int, per_page: int, status: string|null, search: string|null, sort: string, date_from: string|null, date_to: string|null}
     */
    public function validate(array $query): array
    {
        $errors = [];
        foreach ($query as $field => $_value) {
            if (!in_array($field, ['page', 'per_page', 'status', 'search', 'sort', 'date_from', 'date_to'], true)) {
                $errors[$field] = ['Unknown query parameter.'];
            }
        }

        $page = $this->positiveInteger($query['page'] ?? '1');
        if ($page === null) {
            $errors['page'] = ['Enter a positive integer.'];
            $page = 1;
        }

        $perPage = $this->positiveInteger($query['per_page'] ?? '20');
        if ($perPage === null || $perPage > 100) {
            $errors['per_page'] = ['Enter an integer between 1 and 100.'];
            $perPage = 20;
        }

        $status = $this->optionalString($query['status'] ?? null);
        if ($status === false) {
            $errors['status'] = ['Enter a valid status.'];
            $status = null;
        } elseif ($status !== null && !in_array(strtolower($status), self::ALLOWED_STATUSES, true)) {
            $errors['status'] = ['Choose a valid order status.'];
            $status = null;
        } else {
            $status = $status === null ? null : strtolower($status);
        }

        $search = $this->optionalString($query['search'] ?? null);
        if ($search === false || (is_string($search) && mb_strlen($search) > 100)) {
            $errors['search'] = ['Search query must be at most 100 characters.'];
            $search = null;
        }

        $sort = $this->optionalString($query['sort'] ?? 'newest');
        if ($sort === false || !is_string($sort) || !in_array(strtolower($sort), self::ALLOWED_SORTS, true)) {
            $errors['sort'] = ['Choose a supported sort option (newest, oldest, total_high, total_low).'];
            $sort = 'newest';
        } else {
            $sort = strtolower($sort);
        }

        $dateFromRaw = $this->optionalString($query['date_from'] ?? null);
        $dateFrom = null;
        if ($dateFromRaw === false) {
            $errors['date_from'] = ['Enter a valid date.'];
        } elseif (is_string($dateFromRaw)) {
            $dateFrom = $this->parseDate($dateFromRaw);
            if ($dateFrom === null) {
                $errors['date_from'] = ['Enter a valid date format (e.g. YYYY-MM-DD or ISO 8601).'];
            }
        }

        $dateToRaw = $this->optionalString($query['date_to'] ?? null);
        $dateTo = null;
        if ($dateToRaw === false) {
            $errors['date_to'] = ['Enter a valid date.'];
        } elseif (is_string($dateToRaw)) {
            $dateTo = $this->parseDate($dateToRaw);
            if ($dateTo === null) {
                $errors['date_to'] = ['Enter a valid date format (e.g. YYYY-MM-DD or ISO 8601).'];
            }
        }

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $errors['date_from'] = ['date_from cannot be after date_to.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
            'search' => is_string($search) ? $search : null,
            'sort' => $sort,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function optionalString(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : false;
    }

    private function parseDate(string $value): ?string
    {
        $formats = [
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d H:i:s',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false) {
                return $format === 'Y-m-d'
                    ? $date->format('Y-m-d 00:00:00')
                    : $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
