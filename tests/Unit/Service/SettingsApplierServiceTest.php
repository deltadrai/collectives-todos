<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\Collectives\Db\PageMapper;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\PageEmojiService;
use OCA\CollectiveTodos\Service\PageOrderingService;
use OCA\CollectiveTodos\Service\SettingsApplierService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsApplierServiceTest extends TestCase
{
	private CheckboxAggregator $aggregator;
	private TodosPageGenerator $todosPageGenerator;
	private SettingsService $settings;
	private PageOrderingService $ordering;
	private PageEmojiService $emojiService;
	private PageMapper $pageMapper;
	private LoggerInterface $logger;
	private SettingsApplierService $service;
	private Folder $collectiveFolder;
	private string $resolvedFilename = 'Todos.md';
	private bool $enabled = true;

	protected function setUp(): void
	{
		$this->aggregator = $this->createMock(CheckboxAggregator::class);
		$this->todosPageGenerator = $this->createMock(TodosPageGenerator::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->ordering = $this->createMock(PageOrderingService::class);
		$this->emojiService = $this->createMock(PageEmojiService::class);
		$this->pageMapper = $this->getMockBuilder(PageMapper::class)
			->disableOriginalConstructor()->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SettingsApplierService($this->aggregator, $this->todosPageGenerator, $this->settings, $this->ordering, $this->emojiService, $this->pageMapper, $this->logger);
		$this->collectiveFolder = $this->createMock(Folder::class);

		$this->settings->method('resolveTodosPageFilename')
			->willReturnCallback(fn (int $collectiveId) => $this->resolvedFilename);
		$this->settings->method('isEnabled')
			->willReturnCallback(fn (?int $collectiveId) => $this->enabled);
	}

	/**
	 * Wire the collective folder with the given existing node names. The
	 * $getFile callback supplies the file mock returned by get() (defaults
	 * to a plain file mock); names not in $existing throw.
	 */
	private function wireFolder(array $existing, ?callable $getFile = null): Folder
	{
		$folder = $this->collectiveFolder;
		$folder->method('getPath')->willReturn('/appdata_x/collectives/8');
		$folder->method('nodeExists')
			->willReturnCallback(fn (string $name) => in_array($name, $existing, true));
		if ($getFile !== null) {
			$folder->method('get')->willReturnCallback($getFile);
		} else {
			$folder->method('get')->willReturnCallback(function (string $name) use ($existing) {
				if (!in_array($name, $existing, true)) {
					throw new NotFoundException('not found: ' . $name);
				}
				$file = $this->createMock(File::class);
				$file->method('getName')->willReturn($name);
				return $file;
			});
		}
		$this->aggregator->method('getFolder')->willReturn($folder);
		return $folder;
	}

	private function fileWithMove(string $expectedTarget, ?\Throwable $error = null): File
	{
		$file = $this->createMock(File::class);
		$file->method('move')->willReturnCallback(function (string $target) use ($expectedTarget, $error) {
			$this->assertSame($expectedTarget, $target);
			if ($error !== null) {
				throw $error;
			}
		});
		return $file;
	}

	public function testRenamesTodosFileWhenNameChanged(): void
	{
		$this->resolvedFilename = 'Aufgaben.md';
		$folder = $this->wireFolder(
			['Todos.md'],
			fn (string $name) => $this->fileWithMove('/appdata_x/collectives/8/Aufgaben.md')
		);

		$this->ordering->expects($this->once())
			->method('enforcePosition')
			->with($folder, 8);

		$this->assertNull($this->service->applyToCollective(8, 'Todos.md'));
	}

	public function testRenameSkippedWhenTargetExists(): void
	{
		$this->resolvedFilename = 'Aufgaben.md';
		$folder = $this->wireFolder(['Todos.md', 'Aufgaben.md']);

		// The position can still be enforced
		$this->ordering->expects($this->once())
			->method('enforcePosition')
			->with($folder, 8);

		$error = $this->service->applyToCollective(8, 'Todos.md');

		$this->assertNotNull($error);
		$this->assertStringContainsString('Aufgaben.md', $error);
	}

	public function testNoRenameWhenNameUnchanged(): void
	{
		$folder = $this->collectiveFolder;
		$folder->method('getPath')->willReturn('/appdata_x/collectives/8');
		$folder->method('nodeExists')->willReturn(true);
		// The old file must not even be looked up when the name is unchanged
		$folder->expects($this->never())->method('get');
		$this->aggregator->method('getFolder')->willReturn($folder);

		$this->ordering->expects($this->once())
			->method('enforcePosition')
			->with($folder, 8);

		$this->assertNull($this->service->applyToCollective(8, 'Todos.md'));
	}

	public function testSkipsSilentlyWhenNoTodosPage(): void
	{
		$folder = $this->wireFolder(['Readme.md']);

		$this->ordering->expects($this->once())
			->method('enforcePosition')
			->with($folder, 8);
		$this->emojiService->expects($this->once())
			->method('enforceEmoji')
			->with($folder, 8);

		$this->assertNull($this->service->applyToCollective(8, 'Todos.md'));
	}

	public function testAppliesEmoji(): void
	{
		$folder = $this->wireFolder(['Todos.md']);

		$this->emojiService->expects($this->once())
			->method('enforceEmoji')
			->with($folder, 8);

		$this->assertNull($this->service->applyToCollective(8, 'Todos.md'));
	}

	public function testReturnsErrorWhenFolderNotResolvable(): void
	{
		$this->aggregator->method('getFolder')->willThrowException(new \RuntimeException('not found'));

		$this->ordering->expects($this->never())->method('enforcePosition');

		$error = $this->service->applyToCollective(8, 'Todos.md');

		$this->assertNotNull($error);
		$this->assertStringContainsString('not found', $error);
	}

	public function testRenameFailureBecomesError(): void
	{
		$this->resolvedFilename = 'Aufgaben.md';
		$folder = $this->wireFolder(
			['Todos.md'],
			fn (string $name) => $this->fileWithMove('/appdata_x/collectives/8/Aufgaben.md', new \RuntimeException('move failed'))
		);

		$this->ordering->expects($this->never())->method('enforcePosition');

		$error = $this->service->applyToCollective(8, 'Todos.md');

		$this->assertNotNull($error);
		$this->assertStringContainsString('move failed', $error);
	}

	public function testApplySkippedWhenCollectiveDisabled(): void
	{
		$this->enabled = false;

		// Not even the collective folder is resolved for a disabled collective
		$this->aggregator->expects($this->never())->method('getFolder');
		$this->ordering->expects($this->never())->method('enforcePosition');
		$this->emojiService->expects($this->never())->method('enforceEmoji');

		$this->assertNull($this->service->applyToCollective(8, 'Todos.md'));
	}

	public function testEnableCollectiveClearsOverrideAndRegenerates(): void
	{
		$folder = $this->collectiveFolder;
		$this->aggregator->method('getFolder')->willReturn($folder);

		// No sticky enabled override is written - it would shield the
		// collective from the global default toggle forever
		$this->settings->expects($this->never())->method('setOverride');
		$this->settings->expects($this->once())
			->method('clearOverride')
			->with(8, SettingsService::KEY_ENABLED);
		$this->todosPageGenerator->expects($this->once())
			->method('regenerateTodosPage')
			->with($folder);

		$this->assertNull($this->service->enableCollective(8));
	}

	public function testDisableCollectiveWritesFlagBeforeDeletingPage(): void
	{
		$calls = [];
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4149);
		$file->method('delete')->willReturnCallback(static function () use (&$calls): void {
			$calls[] = 'delete';
		});
		$this->wireFolder(
			['Todos.md'],
			fn (string $name) => $file
		);

		$this->settings->method('setOverride')->willReturnCallback(
			static function (int $collectiveId, string $key, string $value) use (&$calls): void {
				$calls[] = 'setOverride:' . $key . '=' . $value;
			}
		);
		$this->pageMapper->expects($this->once())
			->method('deleteByFileId')
			->with(4149);

		$this->assertNull($this->service->disableCollective(8));

		// The disabled flag must be written first: the delete fires events
		// whose listeners must already see the collective as disabled
		$this->assertSame(
			['setOverride:' . SettingsService::KEY_ENABLED . '=' . SettingsService::VALUE_DISABLED, 'delete'],
			$calls
		);
	}

	public function testDisableCollectiveWithoutTodosPage(): void
	{
		$this->collectiveFolder->method('nodeExists')->willReturn(false);
		$this->aggregator->method('getFolder')->willReturn($this->collectiveFolder);

		$this->settings->expects($this->once())
			->method('setOverride')
			->with(8, SettingsService::KEY_ENABLED, SettingsService::VALUE_DISABLED);
		$this->collectiveFolder->expects($this->never())->method('get');
		$this->pageMapper->expects($this->never())->method('deleteByFileId');

		$this->assertNull($this->service->disableCollective(8));
	}

	public function testDisableFailureBecomesError(): void
	{
		$file = $this->createMock(File::class);
		$file->method('delete')->willThrowException(new \RuntimeException('delete failed'));
		$this->wireFolder(
			['Todos.md'],
			fn (string $name) => $file
		);

		// The page row is only removed after a successful file delete
		$this->pageMapper->expects($this->never())->method('deleteByFileId');

		$error = $this->service->disableCollective(8);

		$this->assertNotNull($error);
		$this->assertStringContainsString('delete failed', $error);
	}

	public function testEnableTodosPageRegeneratesWithoutWritingOverride(): void
	{
		$folder = $this->collectiveFolder;
		$this->aggregator->method('getFolder')->willReturn($folder);

		$this->settings->expects($this->never())->method('setOverride');
		$this->settings->expects($this->never())->method('clearOverride');
		$this->todosPageGenerator->expects($this->once())
			->method('regenerateTodosPage')
			->with($folder);

		$this->assertNull($this->service->enableTodosPage(8));
	}

	public function testDisableTodosPageDeletesWithoutWritingOverride(): void
	{
		$calls = [];
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4150);
		$file->method('delete')->willReturnCallback(static function () use (&$calls): void {
			$calls[] = 'delete';
		});
		$this->wireFolder(
			['Todos.md'],
			fn (string $name) => $file
		);

		$this->settings->expects($this->never())->method('setOverride');
		$this->pageMapper->expects($this->once())
			->method('deleteByFileId')
			->with(4150);

		$this->assertNull($this->service->disableTodosPage(8));

		$this->assertSame(['delete'], $calls);
	}
}
