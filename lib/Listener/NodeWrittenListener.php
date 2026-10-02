<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCA\Collectives\Mount\CollectiveStorage;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCA\CollectiveTodos\Service\TodosReverseSyncService;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeWrittenEvent> */
class NodeWrittenListener implements IEventListener
{
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private TodosReverseSyncService $reverseSync;
    private SettingsService $settings;
    private LoggerInterface $logger;

    public function __construct(
        CheckboxParser $parser,
        CheckboxAggregator $aggregator,
        TodosPageGenerator $generator,
        TodosReverseSyncService $reverseSync,
        SettingsService $settings,
        LoggerInterface $logger
    ) {
        $this->parser = $parser;
        $this->aggregator = $aggregator;
        $this->generator = $generator;
        $this->reverseSync = $reverseSync;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Handle file write events from the Nextcloud file system.
     * Only processes Collective markdown files.
     */
    public function handle(Event $event): void
    {
        if (!($event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

        // Filter: only Collective markdown files
        if (!($node instanceof File)
            || $node->getMimeType() !== 'text/markdown'
            || !$node->getStorage()->instanceOfStorage(CollectiveStorage::class)) {
            return;
        }

        try {
            $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);
            $collectiveId = $this->aggregator->getCollectiveId($collectiveFolder);
            $todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

            // Writes to the Todos page itself are reverse-synced to the source
            // pages instead of being aggregated (aggregating them would loop)
            if ($node->getName() === $todosFilename) {
                $this->syncTodosPageToSources($node);
                return;
            }

            $checkboxes = $this->parser->parse($node->getContent());
            $pageId = (string)$node->getId();
            $pageTitle = $this->aggregator->derivePageTitle($node);

            $this->aggregator->updatePageCheckboxes($collectiveFolder, $pageId, $pageTitle, $checkboxes);
            $this->generator->regenerateTodosPage($collectiveFolder);
        } catch (\Throwable $e) {
            $this->logger->warning('collectives_todos: failed to update Todos page for node ' . $node->getId() . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Apply checkbox changes made on the Todos page to the source pages.
     * The source page writes then take the regular aggregation path, which
     * regenerates the Todos page once - that regeneration produces no
     * further reverse-sync changes, so the cycle terminates.
     */
    private function syncTodosPageToSources(File $node): void
    {
        $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);
        $this->reverseSync->syncFromTodosPage($collectiveFolder, $node->getContent());
    }
}
