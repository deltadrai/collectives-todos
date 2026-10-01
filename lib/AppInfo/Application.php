<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'collectives_todos';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register listeners for Nextcloud core file events
        $context->registerEventListener(
            NodeWrittenEvent::class,
            \OCA\CollectiveTodos\Listener\NodeWrittenListener::class
        );

        $context->registerEventListener(
            NodeDeletedEvent::class,
            \OCA\CollectiveTodos\Listener\NodeDeletedListener::class
        );
    }

    public function boot(IBootContext $context): void
    {
    }
}

