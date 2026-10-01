<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Listener;

use OCA\CollectiveTodos\Listener\NodeWrittenListener;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage;
use OCA\Collectives\Mount\CollectiveStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeWrittenListenerTest extends TestCase
{
    private NodeWrittenListener $listener;
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->parser = $this->createMock(CheckboxParser::class);
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->generator = $this->createMock(TodosPageGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new NodeWrittenListener(
            $this->parser,
            $this->aggregator,
            $this->generator,
            $this->logger
        );
    }

    public function testHandleIgnoresNonFileNodes(): void
    {
        $storage = $this->createMock(Storage::class);
        $node = $this->createMock(\OCP\Files\Node::class);
        $node->method('getStorage')->willReturn($storage);

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->parser->expects($this->never())->method('parse');
        $this->aggregator->expects($this->never())->method('updatePageCheckboxes');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonMarkdownFiles(): void
    {
        $storage = $this->createMock(CollectiveStorage::class);
        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/plain');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->parser->expects($this->never())->method('parse');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonCollectiveStorage(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->method('instanceOfStorage')->willReturn(false);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->parser->expects($this->never())->method('parse');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresOwnTodosPage(): void
    {
        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn(TodosPageGenerator::TODOS_PAGE_FILENAME);

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->parser->expects($this->never())->method('parse');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testHandleProcessesCollectiveMarkdownFile(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getId')->willReturn(456);
        $node->method('getName')->willReturn('Test Page.md');
        $node->method('getContent')->willReturn('- [ ] Test task');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $checkboxes = [
            ['text' => 'Test task', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Test task']
        ];
        $this->parser->method('parse')->willReturn($checkboxes);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);
        $this->aggregator->method('derivePageTitle')->willReturn('Test Page');

        $this->aggregator->expects($this->once())->method('updatePageCheckboxes')
            ->with($collectiveFolder, '456', 'Test Page', $checkboxes);

        $this->generator->expects($this->once())->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);
    }
}
