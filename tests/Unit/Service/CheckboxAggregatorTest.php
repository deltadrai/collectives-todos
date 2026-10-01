<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\CheckboxAggregator;
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

    protected function setUp(): void
    {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->config = $this->createMock(IConfig::class);
        $this->aggregator = new CheckboxAggregator($this->rootFolder, $this->config);
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
