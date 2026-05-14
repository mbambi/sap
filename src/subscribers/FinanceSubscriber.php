<?php

declare(strict_types=1);

namespace App;

function registerFinanceSubscriber(EventBus $bus): void
{
    $handler = function (array $event): void {
        error_log(sprintf('FinanceService: Processing %s for tenant %s', $event['type'], $event['tenantId'] ?? 'unknown'));
    };

    foreach (['InvoicePosted', 'PaymentExecuted', 'GoodsReceived'] as $eventType) {
        $bus->register($eventType, $handler);
    }
}
