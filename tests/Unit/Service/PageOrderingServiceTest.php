<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCA\CollectiveTodos\Service\PageOrderingService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class PageOrderingServiceTest extends TestCase
{
	private PageMapper $pageMapper;
	private SettingsService $settings;
	private PageOrderingService $service;
	private Folder $collectiveFolder;
	private string $treePosition = 'top';
	private string $todosFilename = 'Todos.md';
	private string $subpageOrder = '[11,12]';

	protected function setUp(): void
	{
		$this->pageMapper = $this->getMockBuilder(PageMapper::class)
			->disableOriginalConstructor()->getMock();
		$this->settings = $this->createMock(SettingsService::class);
		$this->collectiveFolder = $this->createMock(Folder::class);

		$this->settings->method('resolve')
			->willReturnCallback(fn (?int $collectiveId, string $key) => $this->treePosition);
		$this->settings->method('resolveTodosPageFilename')
			->willReturnCallback(fn (?int $collectiveId) => $this->todosFilename);

		$this->service = new PageOrderingService($this->pageMapper, $this->settings);
	}

	private function givenCollectiveFolderContent(string ...$names): void
	{
		$this->collectiveFolder->method('nodeExists')
			->willReturnCallback(fn (string $name) => in_array($name, $names, true));
		$this->collectiveFolder->method('get')
			->willReturnCallback(function (string $name) use ($names) {
				if (!in_array($name, $names, true)) {
					throw new NotFoundException('not found: ' . $name);
				}
				if ($name === 'Todos.md') {
					return $this->fileWithId(40, $name);
				}
				return $this->fileWithId(10, $name);
			});
	}

	private function fileWithId(int $id, string $name): File
	{
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function givenLandingPageRow(): void
	{
		$page = new Page();
		$page->setSubpageOrder($this->subpageOrder);
		$this->pageMapper->method('findByFileId')->willReturn($page);
		$this->pageMapper->method('update')
			->willReturnCallback(function (Page $page): Page {
				$this->subpageOrder = $page->getSubpageOrder();
				return $page;
			});
	}

	public function testTopInsertsTodosIdAtFirstPosition(): void
	{
		$this->treePosition = 'top';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->pageMapper->expects($this->once())->method('update');

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[40,11,12]', $this->subpageOrder);
	}

	public function testBottomAppendsTodosId(): void
	{
		$this->treePosition = 'bottom';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[11,12,40]', $this->subpageOrder);
	}

	public function testAlphabeticalRemovesTodosId(): void
	{
		$this->treePosition = 'alphabetical';
		$this->subpageOrder = '[11,40,12]';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[11,12]', $this->subpageOrder);
	}

	public function testAlphabeticalAbsentIdIsNoop(): void
	{
		$this->treePosition = 'alphabetical';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[11,12]', $this->subpageOrder);
	}

	public function testPreservesOtherIdsAndDeduplicates(): void
	{
		$this->treePosition = 'top';
		$this->subpageOrder = '[11,40,40,12]';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[40,11,12]', $this->subpageOrder);
	}

	public function testNoopWhenAlreadyCorrect(): void
	{
		$this->treePosition = 'top';
		$this->subpageOrder = '[40,11,12]';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[40,11,12]', $this->subpageOrder);
	}

	public function testHandlesNullAndEmptyOrder(): void
	{
		$this->treePosition = 'top';

		$this->subpageOrder = '[]';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();
		$this->service->enforcePosition($this->collectiveFolder, 8);
		$this->assertSame('[40]', $this->subpageOrder);
	}

	public function testHandlesNullOrderValue(): void
	{
		$this->treePosition = 'bottom';
		$this->subpageOrder = '';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');

		$page = new Page();
		$this->pageMapper->method('findByFileId')->willReturn($page);
		$this->pageMapper->method('update')
			->willReturnCallback(function (Page $page): Page {
				$this->subpageOrder = $page->getSubpageOrder();
				return $page;
			});

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('[40]', $this->subpageOrder);
	}

	public function testSkipsOnCorruptOrder(): void
	{
		$this->treePosition = 'top';
		$this->subpageOrder = 'not json';
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
		$this->givenLandingPageRow();

		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforcePosition($this->collectiveFolder, 8);

		$this->assertSame('not json', $this->subpageOrder);
	}

	public function testSkipsWhenTodosPageOrLandingPageMissing(): void
	{
		$this->givenCollectiveFolderContent('Readme.md');

		$this->pageMapper->expects($this->never())->method('findByFileId');

		$this->service->enforcePosition($this->collectiveFolder, 8);
	}

	public function testSkipsWhenLandingPageRowMissing(): void
	{
		$this->givenCollectiveFolderContent('Todos.md', 'Readme.md');

		$this->pageMapper->method('findByFileId')->willReturn(null);
		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforcePosition($this->collectiveFolder, 8);
	}

    public function testSkipsOnNonIntegerEntries(): void
    {
        $this->treePosition = 'top';
        $this->subpageOrder = '[1,"a"]';
        $this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
        $this->givenLandingPageRow();

        $this->pageMapper->expects($this->never())->method('update');

        $this->service->enforcePosition($this->collectiveFolder, 8);

        $this->assertSame('[1,"a"]', $this->subpageOrder);
    }

    public function testSkipsOnObjectOrder(): void
    {
        $this->treePosition = 'top';
        $this->subpageOrder = '{"a":1}';
        $this->givenCollectiveFolderContent('Todos.md', 'Readme.md');
        $this->givenLandingPageRow();

        $this->pageMapper->expects($this->never())->method('update');

        $this->service->enforcePosition($this->collectiveFolder, 8);

        $this->assertSame('{"a":1}', $this->subpageOrder);
    }
}
