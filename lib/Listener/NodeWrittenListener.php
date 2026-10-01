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
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeWrittenEvent> */
class NodeWrittenListener implements IEventListener
{
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private LoggerInterface $logger;

    public function __construct(
        CheckboxParser $parser,
        CheckboxAggregator $aggregator,
        TodosPageGenerator $generator,
        LoggerInterface $logger
    ) {
        $this->parser = $parser;
        $this->aggregator = $aggregator;
        $this->generator = $generator;
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

        // Filter: only Collective markdown files, but never our own Todos page
        // (writing it triggers another NodeWrittenEvent -> infinite loop)
        if (!($node instanceof File)
            || $node->getMimeType() !== 'text/markdown'
            || $node->getName() === TodosPageGenerator::TODOS_PAGE_FILENAME
            || !$node->getStorage()->instanceOfStorage(CollectiveStorage::class)) {
            return;
        }

        try {
            $collectiveFolder = $this->aggregator->getCollectiveFolderFromNode($node);

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
}
