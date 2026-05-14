<?php

declare(strict_types=1);

namespace App;

function registerAnalyticsSubscriber(EventBus $bus): void
{
    $handler = function (array $event): void {
        // Events are persisted in ProcessEvent; analytics can query from there.
    };

    $bus->register('*', $handler);
}
