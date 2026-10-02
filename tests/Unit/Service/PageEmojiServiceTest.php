<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCA\CollectiveTodos\Service\PageEmojiService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class PageEmojiServiceTest extends TestCase
{
	private PageMapper $pageMapper;
	private SettingsService $settings;
	private PageEmojiService $service;
	private Folder $collectiveFolder;
	private string $emoji = '📋';
	private string $todosFilename = 'Todos.md';
	private string $storedEmoji = 'null';

	protected function setUp(): void
	{
		$this->pageMapper = $this->getMockBuilder(PageMapper::class)
			->disableOriginalConstructor()->getMock();
		$this->settings = $this->createMock(SettingsService::class);
		$this->collectiveFolder = $this->createMock(Folder::class);

		$this->settings->method('resolve')
			->willReturnCallback(fn (?int $collectiveId, string $key) => $this->emoji);
		$this->settings->method('resolveTodosPageFilename')
			->willReturnCallback(fn (?int $collectiveId) => $this->todosFilename);

		$this->service = new PageEmojiService($this->pageMapper, $this->settings);
	}

	private function givenTodosPage(?string $existingEmoji = null): void
	{
		$this->collectiveFolder->method('get')
			->willReturnCallback(function (string $name) {
				if ($name !== 'Todos.md') {
					throw new NotFoundException('not found: ' . $name);
				}
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(40);
				return $file;
			});

		$this->storedEmoji = $existingEmoji === null ? 'null' : $existingEmoji;

		$page = new Page();
		if ($existingEmoji !== null) {
			$page->setEmoji($existingEmoji);
		}
		$this->pageMapper->method('findByFileId')->willReturn($page);
		$this->pageMapper->method('update')->willReturnCallback(function (Page $page): Page {
			$this->storedEmoji = $page->getEmoji() ?? 'null';
			return $page;
		});
	}

	public function testSetsEmojiOnTheTodosPageRow(): void
	{
		$this->emoji = '📋';
		$this->givenTodosPage(null);

		$this->pageMapper->expects($this->once())->method('update');

		$this->service->enforceEmoji($this->collectiveFolder, 8);

		$this->assertSame('📋', $this->storedEmoji);
	}

	public function testReplacesExistingEmoji(): void
	{
		$this->emoji = '🗂️';
		$this->givenTodosPage('📋');

		$this->service->enforceEmoji($this->collectiveFolder, 8);

		$this->assertSame('🗂️', $this->storedEmoji);
	}

	public function testClearsEmojiWhenConfiguredEmpty(): void
	{
		$this->emoji = '';
		$this->givenTodosPage('📋');

		$this->service->enforceEmoji($this->collectiveFolder, 8);

		$this->assertSame('null', $this->storedEmoji);
	}

	public function testNoopWhenEmojiAlreadyMatches(): void
	{
		$this->emoji = '📋';
		$this->givenTodosPage('📋');

		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforceEmoji($this->collectiveFolder, 8);

		$this->assertSame('📋', $this->storedEmoji);
	}

	public function testNoopWhenBothEmpty(): void
	{
		$this->emoji = '';
		$this->givenTodosPage(null);

		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforceEmoji($this->collectiveFolder, 8);

		$this->assertSame('null', $this->storedEmoji);
	}

	public function testSkipsWhenTodosPageMissing(): void
	{
		$this->collectiveFolder->method('get')
			->willThrowException(new NotFoundException('not found'));

		$this->pageMapper->expects($this->never())->method('findByFileId');

		$this->service->enforceEmoji($this->collectiveFolder, 8);
	}

	public function testSkipsWhenPageRowMissing(): void
	{
		$this->collectiveFolder->method('get')->willReturn($this->createMock(File::class));
		$this->pageMapper->method('findByFileId')->willReturn(null);
		$this->pageMapper->expects($this->never())->method('update');

		$this->service->enforceEmoji($this->collectiveFolder, 8);
	}
}
