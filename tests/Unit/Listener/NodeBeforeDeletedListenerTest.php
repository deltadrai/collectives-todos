<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Listener;

use OCA\CollectiveTodos\Listener\NodeBeforeDeletedListener;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCA\Collectives\Mount\CollectiveStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeBeforeDeletedListenerTest extends TestCase
{
    private NodeBeforeDeletedListener $listener;
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

        $this->listener = new NodeBeforeDeletedListener(
            $this->aggregator,
            $this->generator,
            $this->settings,
            $this->logger
        );
    }

    private function collectiveFolder(): Folder
    {
        return $this->createMock(Folder::class);
    }

    public function testIgnoresNonFolderNodes(): void
    {
        $storage = $this->createMock(IStorage::class);
        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testIgnoresNonCollectiveStorage(): void
    {
        $storage = $this->createMock(IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);
        $node = $this->createMock(Folder::class);
        $node->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testRemovesAllMarkdownPagesUnderDeletedFolder(): void
    {
        // A Collectives page is a folder with a Readme.md (the index page)
        // and possibly further .md files (subpages)
        $readme = $this->createMock(File::class);
        $readme->method('getMimeType')->willReturn('text/markdown');
        $readme->method('getName')->willReturn('Readme.md');
        $readme->method('getId')->willReturn(4127);

        $subpage = $this->createMock(File::class);
        $subpage->method('getMimeType')->willReturn('text/markdown');
        $subpage->method('getName')->willReturn('Ein Unterprotokoll.md');
        $subpage->method('getId')->willReturn(4136);

        $image = $this->createMock(File::class);
        $image->method('getMimeType')->willReturn('image/png');
        $image->method('getName')->willReturn('attachment.png');
        $image->method('getId')->willReturn(4140);

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$readme, $subpage, $image]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $collectiveFolder = $this->collectiveFolder();
        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $removed = [];
        $this->aggregator->expects($this->exactly(2))
            ->method('removePage')
            ->willReturnCallback(function (Folder $folder, string $pageId) use (&$removed): void {
                $removed[] = $pageId;
            });

        $this->generator->expects($this->once())
            ->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);

        $this->assertSame(['4127', '4136'], $removed);
    }

    public function testRemovesMarkdownPagesFromNestedFolders(): void
    {
        $nestedMarkdown = $this->createMock(File::class);
        $nestedMarkdown->method('getMimeType')->willReturn('text/markdown');
        $nestedMarkdown->method('getName')->willReturn('Sub.md');
        $nestedMarkdown->method('getId')->willReturn(4137);

        $nestedFolder = $this->createMock(Folder::class);
        $nestedFolder->method('getDirectoryListing')->willReturn([$nestedMarkdown]);

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$nestedFolder]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $collectiveFolder = $this->collectiveFolder();
        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($collectiveFolder);

        $this->aggregator->expects($this->once())
            ->method('removePage')
            ->with($collectiveFolder, '4137');
        $this->generator->expects($this->once())
            ->method('regenerateTodosPage')
            ->with($collectiveFolder);

        $this->listener->handle($event);
    }

    public function testNeverTouchesOwnTodosPage(): void
    {
        $todosPage = $this->createMock(File::class);
        $todosPage->method('getMimeType')->willReturn('text/markdown');
        $todosPage->method('getName')->willReturn('Todos.md');
        $todosPage->method('getId')->willReturn(4148);

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$todosPage]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testSkipsRegenerationWhenFolderHasNoMarkdownPages(): void
    {
        $image = $this->createMock(File::class);
        $image->method('getMimeType')->willReturn('image/png');
        $image->method('getName')->willReturn('attachment.png');

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$image]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($this->collectiveFolder());

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }

    public function testLogsAndSurvivesFailures(): void
    {
        $readme = $this->createMock(File::class);
        $readme->method('getMimeType')->willReturn('text/markdown');
        $readme->method('getName')->willReturn('Readme.md');
        $readme->method('getId')->willReturn(4127);

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$readme]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $this->aggregator->method('getCollectiveFolderFromNode')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('warning');

        $this->listener->handle($event);
    }

    public function testCustomTodosPageNameNotCollected(): void
    {
        $this->todosFilename = 'Aufgaben.md';

        $todosPage = $this->createMock(File::class);
        $todosPage->method('getMimeType')->willReturn('text/markdown');
        $todosPage->method('getName')->willReturn('Aufgaben.md');
        $todosPage->method('getId')->willReturn(4148);

        $pageFolder = $this->createMock(Folder::class);
        $pageFolder->method('getDirectoryListing')->willReturn([$todosPage]);

        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $pageFolder->method('getStorage')->willReturn($storage);

        $event = $this->createMock(BeforeNodeDeletedEvent::class);
        $event->method('getNode')->willReturn($pageFolder);

        $this->aggregator->method('getCollectiveFolderFromNode')->willReturn($this->collectiveFolder());

        $this->aggregator->expects($this->never())->method('removePage');
        $this->generator->expects($this->never())->method('regenerateTodosPage');

        $this->listener->handle($event);
    }
}
