<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCA\Collectives\Mount\CollectiveStorage;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeDeletedEvent> */
class NodeDeletedListener implements IEventListener
{
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private LoggerInterface $logger;

    public function __construct(
        CheckboxAggregator $aggregator,
        TodosPageGenerator $generator,
        LoggerInterface $logger
    ) {
        $this->aggregator = $aggregator;
        $this->generator = $generator;
        $this->logger = $logger;
    }

    /**
     * Handle file delete events from the Nextcloud file system.
     * Only processes Collective markdown files.
     */
    public function handle(Event $event): void
    {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $node = $event->getNode();

        // Filter: only Collective markdown files, but never our own Todos page
        if (!($node instanceof File)
            || $node->getMimeType() !== 'text/markdown'
            || $node->getName() === TodosPageGenerator::TODOS_PAGE_FILENAME
            || !$node->getStorage()->instanceOfStorage(CollectiveStorage::class)) {
            return;
        }

        try {
            $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);
            $pageId = (string)$node->getId();

            $this->aggregator->removePage($collectiveFolder, $pageId);
            $this->generator->regenerateTodosPage($collectiveFolder);
        } catch (\Throwable $e) {
            $this->logger->warning('collectives_todos: failed to update Todos page after deleting node ' . $node->getId() . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
