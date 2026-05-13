<?php

declare(strict_types=1);

namespace App;

final class EventBus
{
    private static ?EventBus $instance = null;
    private array $listeners = [];
    private bool $bootstrapped = false;

    private function __construct()
    {
    }

    public static function getInstance(): EventBus
    {
        if (!self::$instance) {
            self::$instance = new EventBus();
        }

        self::$instance->bootstrapSubscribers();

        return self::$instance;
    }

    public function register(string $eventType, callable $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    public function publish(string $eventType, array $payload): array
    {
        $eventId = generateUuid();
        $timestamp = date('Y-m-d H:i:s');

        $event = [
            'id' => $eventId,
            'type' => $eventType,
            'tenantId' => $payload['tenantId'] ?? null,
            'userId' => $payload['userId'] ?? null,
            'module' => $payload['module'] ?? 'system',
            'documentId' => $payload['documentId'] ?? null,
            'documentType' => $payload['documentType'] ?? null,
            'payload' => $payload['payload'] ?? $payload,
            'correlationId' => $payload['correlationId'] ?? null,
            'timestamp' => $timestamp,
        ];

        $this->persistProcessEvent($event);

        $notified = 0;
        foreach ($this->listeners[$eventType] ?? [] as $listener) {
            $listener($event);
            $notified++;
        }
        foreach ($this->listeners['*'] ?? [] as $listener) {
            $listener($event);
            $notified++;
        }

        return [
            'eventId' => $eventId,
            'subscribersNotified' => $notified,
        ];
    }

    private function persistProcessEvent(array $event): void
    {
        if (!$event['tenantId']) {
            return;
        }

        $db = DB::getInstance();
        $db->execute(
            'INSERT INTO `ProcessEvent` (id, tenantId, caseId, activity, timestamp, resource, module, documentId, attributes, duration)
             VALUES (:id, :tenantId, :caseId, :activity, :timestamp, :resource, :module, :documentId, :attributes, :duration)',
            [
                'id' => generateUuid(),
                'tenantId' => $event['tenantId'],
                'caseId' => $event['correlationId'] ?? $event['documentId'] ?? $event['id'],
                'activity' => $event['type'],
                'timestamp' => $event['timestamp'],
                'resource' => $event['userId'],
                'module' => $event['module'],
                'documentId' => $event['documentId'],
                'attributes' => json_encode(array_merge($event['payload'] ?? [], [
                    'eventType' => $event['type'],
                    'documentType' => $event['documentType'],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration' => null,
            ]
        );
    }

    private function bootstrapSubscribers(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        require_once __DIR__ . '/subscribers/InventorySubscriber.php';
        require_once __DIR__ . '/subscribers/FinanceSubscriber.php';
        require_once __DIR__ . '/subscribers/AnalyticsSubscriber.php';
        require_once __DIR__ . '/subscribers/ProcessMiningSubscriber.php';
        require_once __DIR__ . '/subscribers/NotificationSubscriber.php';

        registerInventorySubscriber($this);
        registerFinanceSubscriber($this);
        registerAnalyticsSubscriber($this);
        registerProcessMiningSubscriber($this);
        registerNotificationSubscriber($this);

        $this->bootstrapped = true;
    }
}
