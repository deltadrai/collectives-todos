<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\SettingsService;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class CheckboxAggregatorTest extends TestCase
{
    private CheckboxAggregator $aggregator;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private SettingsService $settings;
    private int $maxCheckboxes = 0;

    protected function setUp(): void
    {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->config = $this->createMock(IConfig::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->settings->method('resolveInt')
            ->willReturnCallback(fn (?int $collectiveId, string $key) => $this->maxCheckboxes);
        $this->aggregator = new CheckboxAggregator($this->rootFolder, $this->config, $this->settings);
    }

    /**
     * A collective folder whose .collective/todos.json cache content is
     * readable and writable through the passed-by-reference string.
     */
    private function makeCacheFolder(string &$cacheContent): Folder
    {
        $storage = $this->createMock(\OCA\Collectives\Mount\CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $storage->method('getFolderId')->willReturn(8);

        $cacheFile = $this->createMock(File::class);
        $cacheFile->method('getContent')->willReturnCallback(fn () => $cacheContent);
        $cacheFile->method('putContent')->willReturnCallback(function (string $content) use (&$cacheContent): void {
            $cacheContent = $content;
        });

        $folder = $this->createMock(Folder::class);
        $folder->method('getStorage')->willReturn($storage);
        $folder->method('nodeExists')->willReturnCallback(fn (string $name) => $name === '.collective/todos.json' ? $cacheContent !== null : $name === '.collective');
        $folder->method('get')->willReturnCallback(function (string $name) use ($cacheFile, $folder) {
            if ($name === '.collective') {
                return $folder;
            }
            return $cacheFile;
        });
        $folder->method('newFolder')->willReturnCallback(fn (string $name) => $folder);
        return $folder;
    }

    private function storedPage(string $pageId, int $checkboxCount): array
    {
        $checkboxes = [];
        for ($i = 1; $i <= $checkboxCount; $i++) {
            $checkboxes[] = ['text' => 'Task ' . $i, 'checked' => false, 'line' => $i, 'raw' => '- [ ] Task ' . $i];
        }
        return ['title' => 'Page ' . $pageId, 'page_id' => $pageId, 'last_modified' => '2026-10-01T07:00:00Z', 'checkboxes' => $checkboxes];
    }

    private function cacheWithPages(array $pages): string
    {
        return (string)json_encode(['version' => 1, 'updated' => '2026-10-01T07:00:00Z', 'collectives_id' => '8', 'pages' => $pages]);
    }

    public function testUnlimitedByDefault(): void
    {
        $cacheContent = $this->cacheWithPages([]);
        $folder = $this->makeCacheFolder($cacheContent);

        $this->aggregator->updatePageCheckboxes($folder, '1', 'Page 1', $this->storedPage('1', 8)['checkboxes']);

        $cache = json_decode($cacheContent, true);
        $this->assertCount(8, $cache['pages']['1']['checkboxes']);
        $this->assertArrayNotHasKey('truncated', $cache['pages']['1']);
    }

    public function testCapTrimsNewPageAndSetsTruncatedFlag(): void
    {
        $this->maxCheckboxes = 5;
        $cacheContent = $this->cacheWithPages(['1' => $this->storedPage('1', 3)]);
        $folder = $this->makeCacheFolder($cacheContent);

        $this->aggregator->updatePageCheckboxes($folder, '2', 'Page 2', $this->storedPage('2', 4)['checkboxes']);

        $cache = json_decode($cacheContent, true);
        $this->assertCount(2, $cache['pages']['2']['checkboxes']);
        $this->assertTrue($cache['pages']['2']['truncated']);
        // the existing page's entry is untouched
        $this->assertCount(3, $cache['pages']['1']['checkboxes']);
    }

    public function testCapAtZeroRemainingStoresEmptyAndTruncated(): void
    {
        $this->maxCheckboxes = 5;
        $cacheContent = $this->cacheWithPages(['1' => $this->storedPage('1', 5)]);
        $folder = $this->makeCacheFolder($cacheContent);

        $this->aggregator->updatePageCheckboxes($folder, '2', 'Page 2', $this->storedPage('2', 1)['checkboxes']);

        $cache = json_decode($cacheContent, true);
        $this->assertSame([], $cache['pages']['2']['checkboxes']);
        $this->assertTrue($cache['pages']['2']['truncated']);
    }

    public function testExistingEntriesNeverRetrimmed(): void
    {
        $this->maxCheckboxes = 5;
        // Page 2 filled the remaining budget; re-saving page 1 must still
        // store its own checkboxes: its old entry does not count against it
        $cacheContent = $this->cacheWithPages([
            '1' => $this->storedPage('1', 3),
            '2' => $this->storedPage('2', 2),
        ]);
        $folder = $this->makeCacheFolder($cacheContent);

        $this->aggregator->updatePageCheckboxes($folder, '1', 'Page 1', $this->storedPage('1', 3)['checkboxes']);

        $cache = json_decode($cacheContent, true);
        $this->assertCount(3, $cache['pages']['1']['checkboxes']);
        $this->assertArrayNotHasKey('truncated', $cache['pages']['1']);
    }

    public function testGetAllCheckboxesReturnsEmptyArrayForNewCollective(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);
        $collectiveFolder->method('nodeExists')->willReturn(false);

        $result = $this->aggregator->getAllCheckboxes($collectiveFolder);
        $this->assertSame([], $result);
    }

    public function testDerivePageTitleFromReadmeUsesParentFolder(): void
    {
        $node = $this->createMock(File::class);
        $parent = $this->createMock(Folder::class);

        $node->method('getName')->willReturn('Readme.md');
        $parent->method('getName')->willReturn('Ein Demo Protokoll');
        $node->method('getParent')->willReturn($parent);

        $this->assertSame('Ein Demo Protokoll', $this->aggregator->derivePageTitle($node));
    }

    public function testDerivePageTitleFromMarkdownFile(): void
    {
        $node = $this->createMock(File::class);
        $node->method('getName')->willReturn('Ein Unterprotokoll.md');

        $this->assertSame('Ein Unterprotokoll', $this->aggregator->derivePageTitle($node));
    }

    public function testGetCollectiveFolderFromNodeResolvesViaAppdataPath(): void
    {
        $storage = $this->createMock(\OCA\Collectives\Mount\CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $storage->method('getFolderId')->willReturn(8);

        $collectiveFolder = $this->createMock(Folder::class);
        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);

        $this->config->method('getSystemValueString')->willReturn('ocabc123');
        $this->rootFolder->method('get')
            ->with('appdata_ocabc123/collectives/8')
            ->willReturn($collectiveFolder);

        $result = $this->aggregator->getCollectiveFolderFromNode($node);
        $this->assertSame($collectiveFolder, $result);
    }

    public function testGetCollectiveFolderFromNodeThrowsWhenFolderNotResolvable(): void
    {
        $storage = $this->createMock(\OCA\Collectives\Mount\CollectiveStorage::class);
        $storage->method('instanceOfStorage')->willReturn(true);
        $storage->method('getFolderId')->willReturn(999);

        $node = $this->createMock(File::class);
        $node->method('getStorage')->willReturn($storage);

        $this->config->method('getSystemValueString')->willReturn('ocabc123');
        $this->rootFolder->method('get')
            ->willThrowException(new NotFoundException('not found'));
        $this->rootFolder->method('getById')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->aggregator->getCollectiveFolderFromNode($node);
    }

    public function testGetCollectiveFolderFromNodeThrowsOnNonCollectiveStorage(): void
    {
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $node = $this->createMock(File::class);

        $storage->method('instanceOfStorage')->willReturn(false);
        $node->method('getStorage')->willReturn($storage);

        $this->expectException(\InvalidArgumentException::class);
        $this->aggregator->getCollectiveFolderFromNode($node);
    }

    public function testGetFolderResolvesCollectiveIdViaAppdataPath(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);

        $this->config->method('getSystemValueString')->willReturn('ocabc123');
        $this->rootFolder->method('get')
            ->with('appdata_ocabc123/collectives/8')
            ->willReturn($collectiveFolder);

        $result = $this->aggregator->getFolder('8');
        $this->assertSame($collectiveFolder, $result);
    }

    public function testGetFolderResolvesPathDirectly(): void
    {
        $collectiveFolder = $this->createMock(Folder::class);

        $this->rootFolder->method('get')
            ->with('/some/path')
            ->willReturn($collectiveFolder);

        $result = $this->aggregator->getFolder('/some/path');
        $this->assertSame($collectiveFolder, $result);
    }
}
