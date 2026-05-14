<?php

declare(strict_types=1);

namespace App;

function registerNotificationSubscriber(EventBus $bus): void
{
    $handler = function (array $event): void {
        $userId = $event['userId'] ?? null;
        $tenantId = $event['tenantId'] ?? null;

        if (!$userId || !$tenantId) {
            return;
        }

        $db = DB::getInstance();
        $db->execute(
            'INSERT INTO `Notification` (id, tenantId, userId, type, title, message, module, link, isRead, createdAt)
             VALUES (:id, :tenantId, :userId, :type, :title, :message, :module, :link, :isRead, :createdAt)',
            [
                'id' => generateUuid(),
                'tenantId' => $tenantId,
                'userId' => $userId,
                'type' => 'info',
                'title' => formatEventTitle($event['type']),
                'message' => sprintf('%s — %s: %s', $event['type'], $event['documentType'] ?? 'Document', $event['documentId'] ?? 'N/A'),
                'module' => $event['module'] ?? null,
                'link' => $event['documentId'] ? sprintf('/%s/%s', $event['module'] ?? 'module', $event['documentId']) : null,
                'isRead' => 0,
                'createdAt' => date('Y-m-d H:i:s'),
            ]
        );
    };

    $eventTypes = [
        'PurchaseOrderApproved',
        'StockBelowSafetyLevel',
        'ProductionOrderReleased',
        'QualityInspectionCompleted',
        'WorkflowTaskCompleted',
    ];

    foreach ($eventTypes as $eventType) {
        $bus->register($eventType, $handler);
    }
}

function formatEventTitle(string $type): string
{
    return trim(preg_replace('/([A-Z])/', ' $1', $type) ?? $type);
}
