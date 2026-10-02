<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Listener;

use OCA\CollectiveTodos\Listener\NodeDeletedListener;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCA\Collectives\Mount\CollectiveStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeDeletedListenerTest extends TestCase
{
    private NodeDeletedListener $listener;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private SettingsService $settings;
    private LoggerInterface $logger;
    private string $todosFilename = 'Todos.md';

    protected function setUp(): void
    {
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->generator = $this->createMock(TodosPageGenerator::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->settings->method('resolveTodosPageFilename')
            ->willReturnCallback(fn () => $this->todosFilename);

        $this->listener = new NodeDeletedListener(
            $this->aggregator,
            $this->generator,
            $this->settings,
            $this->logger
        );
    }

    public function testHandleIgnoresNonFileNodes(): void
    {
        $storage = $this->createMock(IStorage::class);
        $node = $this->createMock(\OCP\Files\Node::class);
        $node->method('getStorage')->willReturn($storage);

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->expects($this->never())->method('removePage');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonMarkdownFiles(): void
    {
        $storage = $this->createMock(CollectiveStorage::class);
        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/plain');

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->expects($this->never())->method('removePage');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonCollectiveStorage(): void
    {
        $storage = $this->createMock(IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->expects($this->never())->method('removePage');

        $this->listener->handle($event);
    }

    public function testHandleRecreatesDeletedTodosPage(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn('Todos.md');

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        // The Todos page is managed: deleting it recreates it right away.
        // It is never cached as a source page, so no removePage happens.
        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->once())->method('regenerateTodosPage')
            ->with($collectiveFolder);

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

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->aggregator->expects($this->once())->method('removePage')
            ->with($collectiveFolder, '456');

        $this->generator->expects($this->once())->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);
    }

    public function testCustomTodosPageNameIsRecreated(): void
    {
        $this->todosFilename = 'Aufgaben.md';
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn('Aufgaben.md');

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->once())->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);
    }

    public function testDefaultNameIsRemovedWhenOverridden(): void
    {
        $this->todosFilename = 'Aufgaben.md';
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getId')->willReturn(4148);
        $node->method('getName')->willReturn('Todos.md');

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->aggregator->expects($this->once())->method('removePage')
            ->with($collectiveFolder, '4148');
        $this->generator->expects($this->once())->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);
    }
}
