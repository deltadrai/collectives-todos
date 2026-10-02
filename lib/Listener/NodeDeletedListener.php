<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCA\Collectives\Mount\CollectiveStorage;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeDeletedEvent> */
class NodeDeletedListener implements IEventListener
{
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private SettingsService $settings;
    private LoggerInterface $logger;

    public function __construct(
        CheckboxAggregator $aggregator,
        TodosPageGenerator $generator,
        SettingsService $settings,
        LoggerInterface $logger
    ) {
        $this->aggregator = $aggregator;
        $this->generator = $generator;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Handle file delete events from the Nextcloud file system.
     * Only processes Collective markdown files. Deleting the Todos page
     * itself recreates it; deleting any other page updates the cache and
     * regenerates the Todos page.
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
            || !$node->getStorage()->instanceOfStorage(CollectiveStorage::class)) {
            return;
        }

        try {
            $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);
            $collectiveId = $this->aggregator->getCollectiveId($collectiveFolder);
            $todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

            if ($node->getName() === $todosFilename) {
                // The Todos page itself was deleted: recreate it right away.
                // This is race-free - Collectives pages are moved to trash
                // (the move is complete before this event fires) and appdata
                // paths are never locked by the filesystem.
                $this->generator->regenerateTodosPage($collectiveFolder);
                return;
            }

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
