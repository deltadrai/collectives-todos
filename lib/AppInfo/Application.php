<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;

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

        // Folders (Collectives pages) delete their markdown children without
        // per-child events, so those pages must be collected before the delete
        $context->registerEventListener(
            BeforeNodeDeletedEvent::class,
            \OCA\CollectiveTodos\Listener\NodeBeforeDeletedListener::class
        );
    }

    public function boot(IBootContext $context): void
    {
    }
}

