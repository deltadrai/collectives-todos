<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Listener;

use OCA\CollectiveTodos\Listener\NodeWrittenListener;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosReverseSyncService;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCA\Collectives\Mount\CollectiveStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeWrittenListenerTest extends TestCase
{
    private NodeWrittenListener $listener;
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;
    private TodosReverseSyncService $reverseSync;
    private SettingsService $settings;
    private LoggerInterface $logger;
    private string $todosFilename = 'Todos.md';
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->parser = $this->createMock(CheckboxParser::class);
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->generator = $this->createMock(TodosPageGenerator::class);
        $this->reverseSync = $this->createMock(TodosReverseSyncService::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->settings->method('resolveTodosPageFilename')
            ->willReturnCallback(fn () => $this->todosFilename);
        $this->settings->method('isEnabled')
            ->willReturnCallback(fn () => $this->enabled);

        $this->listener = new NodeWrittenListener(
            $this->parser,
            $this->aggregator,
            $this->generator,
            $this->reverseSync,
            $this->settings,
            $this->logger
        );
    }

    public function testHandleIgnoresNonFileNodes(): void
    {
        $storage = $this->createMock(IStorage::class);
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
        $storage = $this->createMock(IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->parser->expects($this->never())->method('parse');

        $this->listener->handle($event);
    }

    public function testHandleRoutesTodosPageWriteToReverseSync(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn('Todos.md');
        $node->method('getContent')->willReturn('- [x] Test task');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->reverseSync->expects($this->once())
            ->method('syncFromTodosPage')
            ->with($collectiveFolder, '- [x] Test task');

        // The Todos page itself must not be re-aggregated or regenerated here;
        // the source page writes triggered by the sync do that
        $this->parser->expects($this->never())->method('parse');
        $this->aggregator->expects($this->never())->method('updatePageCheckboxes');
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

    public function testCustomTodosPageNameTriggersReverseSync(): void
    {
        $this->todosFilename = 'Aufgaben.md';
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn('Aufgaben.md');
        $node->method('getContent')->willReturn('- [x] Test task');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->reverseSync->expects($this->once())
            ->method('syncFromTodosPage')
            ->with($collectiveFolder, '- [x] Test task');
        $this->aggregator->expects($this->never())->method('updatePageCheckboxes');

        $this->listener->handle($event);
    }

    public function testTodosPageWriteSkippedWhenCollectiveDisabled(): void
    {
        $this->enabled = false;
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getName')->willReturn('Todos.md');
        $node->method('getContent')->willReturn('- [x] Test task');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        // A disabled collective is not reverse-synced: its Todos page is
        // not managed and could be a stale snapshot restored from trash
        $this->reverseSync->expects($this->never())->method('syncFromTodosPage');
        $this->aggregator->expects($this->never())->method('updatePageCheckboxes');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testDefaultNameNotTreatedAsTodosPageWhenOverridden(): void
    {
        $this->todosFilename = 'Aufgaben.md';
        $collectiveFolder = $this->createMock(Folder::class);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);
        $node->method('getMimeType')->willReturn('text/markdown');
        $node->method('getId')->willReturn(456);
        $node->method('getName')->willReturn('Todos.md');
        $node->method('getContent')->willReturn('- [ ] Test task');

        $event = $this->createMock(NodeWrittenEvent::class);
        $event->method('getNode')->willReturn($node);

        $checkboxes = [
            ['text' => 'Test task', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Test task']
        ];
        $this->parser->method('parse')->willReturn($checkboxes);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);
        $this->aggregator->method('derivePageTitle')->willReturn('Todos');

        $this->reverseSync->expects($this->never())->method('syncFromTodosPage');
        $this->aggregator->expects($this->once())->method('updatePageCheckboxes')
            ->with($collectiveFolder, '456', 'Todos', $checkboxes);

        $this->listener->handle($event);
    }
}
