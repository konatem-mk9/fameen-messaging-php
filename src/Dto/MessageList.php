<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Page renvoyée par `GET /v1/messages` (liste paginée + compteurs).
 */
final class MessageList
{
    use CastsFromArray;

    public function __construct(
        /** @var list<MessageResource> */
        public readonly array $data,
        public readonly int $page,
        /** Taille de page effective (max 100). */
        public readonly int $limit,
        public readonly int $total,
        public readonly int $totalPages,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        $rows = $data['data'] ?? null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = MessageResource::fromArray($row);
                }
            }
        }

        return new self(
            data: $items,
            page: self::toInt($data['page'] ?? null),
            limit: self::toInt($data['limit'] ?? null),
            total: self::toInt($data['total'] ?? null),
            totalPages: self::toInt($data['totalPages'] ?? null),
        );
    }
}
