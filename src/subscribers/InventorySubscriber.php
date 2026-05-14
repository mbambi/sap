<?php

declare(strict_types=1);

namespace App;

function registerInventorySubscriber(EventBus $bus): void
{
    $handler = function (array $event): void {
        $payload = $event['payload'] ?? [];
        $materialId = $payload['materialId'] ?? null;
        $quantity = $payload['quantity'] ?? null;
        $tenantId = $event['tenantId'] ?? null;

        if (!$tenantId || !$materialId || !is_numeric($quantity)) {
            return;
        }

        $db = DB::getInstance();

        if ($event['type'] === 'GoodsReceived') {
            $db->execute(
                'UPDATE `Material` SET stockQuantity = stockQuantity + :qty WHERE id = :id AND tenantId = :tenantId',
                ['qty' => (float) $quantity, 'id' => $materialId, 'tenantId' => $tenantId]
            );
        }

        if ($event['type'] === 'GoodsIssued') {
            $db->execute(
                'UPDATE `Material` SET stockQuantity = stockQuantity - :qty WHERE id = :id AND tenantId = :tenantId',
                ['qty' => (float) $quantity, 'id' => $materialId, 'tenantId' => $tenantId]
            );
        }

        if ($event['type'] === 'InventoryAdjusted') {
            $delta = $payload['quantityDelta'] ?? $quantity;
            if (is_numeric($delta)) {
                $db->execute(
                    'UPDATE `Material` SET stockQuantity = stockQuantity + :qty WHERE id = :id AND tenantId = :tenantId',
                    ['qty' => (float) $delta, 'id' => $materialId, 'tenantId' => $tenantId]
                );
            }
        }
    };

    foreach (['GoodsReceived', 'GoodsIssued', 'InventoryAdjusted', 'StockBelowSafetyLevel'] as $eventType) {
        $bus->register($eventType, $handler);
    }
}
