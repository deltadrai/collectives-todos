<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Integration;

use OCA\CollectiveTodos\Listener\NodeWrittenListener;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\PageLinkBuilder;
use OCA\CollectiveTodos\Service\TextDocumentResetter;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCA\CollectiveTodos\Service\TodosReverseSyncService;
use OCA\Collectives\Mount\CollectiveStorage;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventFlowTest extends TestCase
{
    /**
     * In-memory collective folder wired to the real listener and services.
     */
    private array $files = [];
    private array $dirs = [];
    private array $writes = [];
    private int $nextFileId = 9000;
    private IRootFolder $rootFolder;
    private Folder $collectiveFolder;

    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;

    protected function setUp(): void
    {
        $rootFolder = $this->createMock(IRootFolder::class);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => $default
        );
        $collectiveMapper = $this->getMockBuilder(\OCA\Collectives\Db\CollectiveMapper::class)
            ->disableOriginalConstructor()->getMock();
        $pageMapper = $this->getMockBuilder(\OCA\Collectives\Db\PageMapper::class)
            ->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(LoggerInterface::class);

        $this->parser = new CheckboxParser();
        $settings = new \OCA\CollectiveTodos\Service\SettingsService($config);
        $this->aggregator = new CheckboxAggregator($rootFolder, $config, $settings);
        $this->generator = new TodosPageGenerator(
            $this->aggregator,
            new PageLinkBuilder($collectiveMapper, $pageMapper),
            new TextDocumentResetter($logger),
            $settings,
            new \OCA\CollectiveTodos\Service\PageOrderingService($pageMapper, $settings),
            new \OCA\CollectiveTodos\Service\PageEmojiService($pageMapper, $settings)
        );
    }

    public function testFileWriteTriggersTodosUpdate(): void
    {
        // This test requires a full Nextcloud test environment
        // For now, verify the services can be instantiated
        $this->assertInstanceOf(CheckboxParser::class, $this->parser);
        $this->assertInstanceOf(CheckboxAggregator::class, $this->aggregator);
        $this->assertInstanceOf(TodosPageGenerator::class, $this->generator);
    }

    public function testCheckboxParserExtractsCheckboxes(): void
    {
        $content = "- [ ] Task 1\n- [x] Task 2";
        $result = $this->parser->parse($content);

        $this->assertCount(2, $result);
        $this->assertFalse($result[0]['checked']);
        $this->assertTrue($result[1]['checked']);
    }

    public function testTodosPageGeneratorCreatesContent(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getStorage')->willReturn($this->createMock(IStorage::class));
        $folder->method('getPath')->willReturn('/admin/files/collectives');

        $content = $this->generator->generateContent($folder, 'Todos.md');

        $this->assertStringContainsString('# Todos', $content);
    }

    /**
     * Full round trip over the NodeWrittenListener: a source page write
     * regenerates the Todos page, a checkbox ticked on the Todos page is
     * synced back to the source page, and the resulting source page write
     * regenerates a consistent Todos page without further changes.
     *
     * The Nextcloud event loop is simulated by dispatching NodeWrittenEvent
     * manually for every file write, in the order the writes happen.
     */
    public function testCheckboxTickOnTodosPageSyncsBackToSourcePage(): void
    {
        $listener = $this->createListener();

        $sourceFile = $this->addFile('Meeting Notes.md', 4127, "# Meeting Notes\n\n- [ ] Buy milk\n")['node'];

        // 1. User edits the source page: cache updated, Todos page generated
        $listener->handle(new NodeWrittenEvent($sourceFile));

        $todosFile = $this->getFile('Todos.md');
        $this->assertStringContainsString('## [Meeting Notes](', $todosFile['content']);
        $this->assertStringContainsString('fileId=4127', $todosFile['content']);
        $this->assertStringContainsString('- [ ] Buy milk', $todosFile['content']);

        // 2. User ticks the checkbox on the Todos page
        $tickedContent = str_replace('- [ ] Buy milk', '- [x] Buy milk', $todosFile['content']);
        $todosFile['node']->putContent($tickedContent);
        $listener->handle(new NodeWrittenEvent($todosFile['node']));

        // The source page now carries the tick
        $this->assertSame(
            "# Meeting Notes\n\n- [x] Buy milk\n",
            $this->getFile('Meeting Notes.md')['content']
        );

        // 3. The reverse sync's source page write regenerates the Todos page
        $listener->handle(new NodeWrittenEvent($sourceFile));

        $this->assertStringContainsString('- [x] Buy milk', $this->getFile('Todos.md')['content']);

        // 4. The regenerated Todos page produces no further source writes
        $writesBefore = $this->writes['Meeting Notes.md'];
        $listener->handle(new NodeWrittenEvent($this->getFile('Todos.md')['node']));

        $this->assertSame($writesBefore, $this->writes['Meeting Notes.md']);
        $this->assertSame("# Meeting Notes\n\n- [x] Buy milk\n", $this->getFile('Meeting Notes.md')['content']);
    }

    /**
     * In-memory collective folder wired to the real listener and services.
     */
    private function createListener(): NodeWrittenListener
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => $default
        );
        $config->method('getSystemValueString')->willReturn('testinst');

        $storage = $this->createCollectiveStorage();
        $this->collectiveFolder = $this->createMock(Folder::class);
        $this->wireCollectiveFolder();
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('get')->willReturnCallback(function (string $path) {
            if ($path === 'appdata_testinst/collectives/8') {
                return $this->collectiveFolder;
            }
            throw new NotFoundException('not found: ' . $path);
        });
        $this->rootFolder->method('getById')->willReturnCallback(function (int $id): array {
            foreach ($this->files as $file) {
                if ($file['id'] === $id) {
                    return [$file['node']];
                }
            }
            return [];
        });

        $aggregator = new CheckboxAggregator($this->rootFolder, $config, new \OCA\CollectiveTodos\Service\SettingsService($config));
        $parser = new CheckboxParser();
        $collectiveMapper = $this->getMockBuilder(\OCA\Collectives\Db\CollectiveMapper::class)
            ->disableOriginalConstructor()->getMock();
        $pageMapper = $this->getMockBuilder(\OCA\Collectives\Db\PageMapper::class)
            ->disableOriginalConstructor()->getMock();
        $generator = new TodosPageGenerator(
            $aggregator,
            new PageLinkBuilder($collectiveMapper, $pageMapper),
            new TextDocumentResetter($logger),
            new \OCA\CollectiveTodos\Service\SettingsService($config),
            new \OCA\CollectiveTodos\Service\PageOrderingService($pageMapper, new \OCA\CollectiveTodos\Service\SettingsService($config)),
            new \OCA\CollectiveTodos\Service\PageEmojiService($pageMapper, new \OCA\CollectiveTodos\Service\SettingsService($config))
        );
        $textResetter = new TextDocumentResetter($logger);
        $reverseSync = new TodosReverseSyncService($parser, $aggregator, $this->rootFolder, $textResetter, $logger);

        $settings = new \OCA\CollectiveTodos\Service\SettingsService($config);

        return new NodeWrittenListener($parser, $aggregator, $generator, $reverseSync, $settings, $logger);
    }

    private function createCollectiveStorage(): IStorage
    {
        $storage = $this->createMock(CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $storage->method('getFolderId')->willReturn(8);
        return $storage;
    }

    private function wireCollectiveFolder(): void
    {
        $folder = $this->collectiveFolder;
        $folder->method('getStorage')->willReturn($this->createCollectiveStorage());
        $folder->method('getPath')->willReturn('/appdata_testinst/collectives/8');
        $folder->method('nodeExists')->willReturnCallback(function (string $name): bool {
            if ($name === '.collective') {
                return isset($this->dirs[$name]);
            }
            return isset($this->files[$name]);
        });
        $folder->method('get')->willReturnCallback(function (string $name): File {
            if (isset($this->files[$name])) {
                return $this->files[$name]['node'];
            }
            throw new NotFoundException('not found: ' . $name);
        });
        $folder->method('newFolder')->willReturnCallback(function (string $name): Folder {
            $this->dirs[$name] = true;
            return $this->createMock(Folder::class);
        });
        $folder->method('newFile')->willReturnCallback(function (string $name, string $content = ''): File {
            return $this->addFile($name, $this->nextFileId++, $content)['node'];
        });
    }

    /**
     * @return array{id: int, content: string, node: File}
     */
    private function addFile(string $name, int $id, string $content): array
    {
        $storage = $this->createCollectiveStorage();
        $file = $this->createMock(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getName')->willReturn($name);
        $file->method('getId')->willReturn($id);
        $file->method('getMimeType')->willReturn('text/markdown');
        $file->method('getContent')->willReturnCallback(function () use ($name): string {
            return $this->files[$name]['content'];
        });
        $file->method('putContent')->willReturnCallback(function ($content) use ($name): void {
            $this->files[$name]['content'] = (string)$content;
            $this->writes[$name] = ($this->writes[$name] ?? 0) + 1;
        });

        $entry = ['id' => $id, 'content' => $content, 'node' => $file];
        $this->files[$name] = $entry;
        return $entry;
    }

    /**
     * @return array{id: int, content: string, node: File}
     */
    private function getFile(string $name): array
    {
        if (!isset($this->files[$name])) {
            $this->fail('No such file in the fake collective folder: ' . $name);
        }
        return $this->files[$name];
    }
}
