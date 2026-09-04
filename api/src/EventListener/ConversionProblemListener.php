<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Conversion\Exception\ConversionProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The one place a problem becomes a response. The priority puts it ahead of
 * API Platform's listener, which would drop the extra members.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
final class ConversionProblemListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $problem = $event->getThrowable();

        if (!$problem instanceof ConversionProblem) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'type' => $problem->type,
                'title' => $problem->title,
                'status' => $problem->status,
                'detail' => $problem->getMessage(),
                ...$problem->extensions,
            ],
            $problem->status,
            ['Content-Type' => 'application/problem+json'],
        ));

        $event->stopPropagation();
    }
}
