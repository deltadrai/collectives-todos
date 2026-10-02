<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCA\Collectives\Mount\CollectiveStorage;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use Psr\Log\LoggerInterface;

/**
 * Clean up cached pages when a folder inside a collective is deleted.
 *
 * Deleting a Collectives page deletes a folder (an index page is the
 * Readme.md inside its page folder, subpages are further files in it).
 * The filesystem only emits events for the folder itself - the markdown
 * files inside are removed at storage level without their own events -
 * so the pages have to be collected before the delete happens, while the
 * children are still listable.
 *
 * @template-implements IEventListener<BeforeNodeDeletedEvent>
 */
class NodeBeforeDeletedListener implements IEventListener
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

    public function handle(Event $event): void
    {
        if (!($event instanceof BeforeNodeDeletedEvent)) {
            return;
        }

        $node = $event->getNode();

        // Filter: only folders on Collective storage. Plain markdown files
        // are handled by the NodeDeletedListener after the delete.
        if (!($node instanceof Folder)
            || !$node->getStorage()->instanceOfStorage(CollectiveStorage::class)) {
            return;
        }

        try {
            $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);
            $collectiveId = $this->aggregator->getCollectiveId($collectiveFolder);
            $todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

            $pageIds = $this->collectMarkdownPageIds($node, $todosFilename);
            if ($pageIds === []) {
                return;
            }

            foreach ($pageIds as $pageId) {
                $this->aggregator->removePage($collectiveFolder, $pageId);
            }
            $this->generator->regenerateTodosPage($collectiveFolder);
        } catch (\Throwable $e) {
            $this->logger->warning('collectives_todos: failed to update Todos page for folder ' . $node->getId() . ' before deletion: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Collect the file ids of all markdown pages under a folder that is
     * about to be deleted, except the Todos page itself.
     *
     * @return array<int, string>
     */
    private function collectMarkdownPageIds(Folder $folder, string $todosFilename): array
    {
        $pageIds = [];
        foreach ($folder->getDirectoryListing() as $child) {
            if ($child instanceof Folder) {
                $pageIds = array_merge($pageIds, $this->collectMarkdownPageIds($child, $todosFilename));
                continue;
            }
            if ($child instanceof File
                && $child->getMimeType() === 'text/markdown'
                && $child->getName() !== $todosFilename) {
                $pageIds[] = (string)$child->getId();
            }
        }
        return array_values(array_unique($pageIds));
    }
}
