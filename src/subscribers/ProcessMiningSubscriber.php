<?php

declare(strict_types=1);

namespace App;

function registerProcessMiningSubscriber(EventBus $bus): void
{
    $handler = function (array $event): void {
        // Process mining captures are already persisted in ProcessEvent.
    };

    $eventTypes = [
        'PurchaseRequisitionCreated',
        'PurchaseOrderCreated',
        'PurchaseOrderApproved',
        'GoodsReceived',
        'InvoicePosted',
        'PaymentExecuted',
        'SalesOrderCreated',
        'DeliveryCreated',
        'ProductionOrderCreated',
        'ProductionOrderReleased',
        'ProductionOrderCompleted',
    ];

    foreach ($eventTypes as $eventType) {
        $bus->register($eventType, $handler);
    }
}
