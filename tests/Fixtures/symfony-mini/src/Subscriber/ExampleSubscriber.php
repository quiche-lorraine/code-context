<?php

declare(strict_types=1);

namespace App\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ExampleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onRequest',
            'kernel.response' => ['onResponse', 100],
            'app.signed' => [
                ['onSignedHigh', 200],
                ['onSignedLow', -10],
            ],
        ];
    }

    public function onRequest(): void
    {
    }

    public function onResponse(): void
    {
    }

    public function onSignedHigh(): void
    {
    }

    public function onSignedLow(): void
    {
    }
}
