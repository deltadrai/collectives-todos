<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TextDocumentResetter;
use OCA\CollectiveTodos\Service\TodosReverseSyncService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TodosReverseSyncServiceTest extends TestCase
{
    private CheckboxAggregator $aggregator;
    private IRootFolder $rootFolder;
    private TextDocumentResetter $textResetter;
    private LoggerInterface $logger;
    private Folder $collectiveFolder;
    private TodosReverseSyncService $service;

    protected function setUp(): void
    {
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->textResetter = $this->createMock(TextDocumentResetter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->collectiveFolder = $this->createMock(Folder::class);

        $this->service = new TodosReverseSyncService(
            new CheckboxParser(),
            $this->aggregator,
            $this->rootFolder,
            $this->textResetter,
            $this->logger
        );
    }

    /**
     * @param array<array{text: string, checked: bool, line: int, raw: string}> $checkboxes
     */
    private function cacheWithPage(string $pageId, string $title, array $checkboxes): void
    {
        $this->aggregator->method('getAllCheckboxes')->willReturn([
            $pageId => [
                'title' => $title,
                'page_id' => $pageId,
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => $checkboxes,
            ],
        ]);
    }

    private function mockSourceFile(string $content, int $id = 4127): File
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getContent')->willReturn($content);
        return $file;
    }

    public function testChecksSourcePageWhenCheckboxTickedInTodosPage(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 3, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n"
            . "## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)\n\n"
            . "- [x] Buy milk\n";

        $file = $this->mockSourceFile("# Meeting Notes\n\n- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $captured = '';
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function (string $content) use (&$captured): void {
                $captured = $content;
            });

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
        $this->assertStringContainsString('- [x] Buy milk', $captured);
    }

	public function testSyncsWhenEditorTrimmedTrailingWhitespace(): void
	{
		// The cache holds the source line's raw text including its trailing
		// space; the Text editor strips trailing whitespace on save, so the
		// Todos page line arrives trimmed and must still match the cache
		$this->cacheWithPage('4127', 'Meeting Notes', [
			['text' => 'Projekt ', 'checked' => false, 'line' => 3, 'raw' => '- [ ] Projekt '],
		]);

		$todosContent = "# Todos\n\n"
			. "## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)\n\n"
			. "- [x] Projekt\n";

		$file = $this->mockSourceFile("# Meeting Notes\n\n- [ ] Projekt \n");
		$this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

		$captured = '';
		$file->expects($this->once())
			->method('putContent')
			->willReturnCallback(function (string $content) use (&$captured): void {
				$captured = $content;
			});

		$count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

		$this->assertSame(1, $count);
		$this->assertStringContainsString('- [x] Projekt ', $captured);
	}

    public function testResetsTextDocumentStateOfSyncedSourcePage(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 3, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n"
            . "## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)\n\n"
            . "- [x] Buy milk\n";

        $file = $this->mockSourceFile("# Meeting Notes\n\n- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);
        $file->method('putContent')->willReturnCallback(static function (): void {
        });

        // The Text editor keeps per-file session state (etag, checksum,
        // steps) for the source page; without a reset it serves the stale
        // content and rejects saves after our external write
        $this->textResetter->expects($this->once())
            ->method('resetForFile')
            ->with(4127);

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
    }

    public function testUnchecksSourcePageWhenCheckboxUntickedInTodosPage(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => true, 'line' => 3, 'raw' => '- [x] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n"
            . "## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)\n\n"
            . "- [ ] Buy milk\n";

        $file = $this->mockSourceFile("# Meeting Notes\n\n- [x] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $captured = '';
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function (string $content) use (&$captured): void {
                $captured = $content;
            });

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
        $this->assertStringContainsString('- [ ] Buy milk', $captured);
    }

    public function testWritesNothingWhenStatesAlreadyMatch(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 3, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n"
            . "## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)\n\n"
            . "- [ ] Buy milk\n";

        $file = $this->mockSourceFile("# Meeting Notes\n\n- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->willReturn([$file]);

        $file->expects($this->never())->method('putContent');
        $this->textResetter->expects($this->never())->method('resetForFile');

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(0, $count);
    }

    public function testResolvesSectionByLinkUrlPageId(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
        ]);

        // Heading text does not match the cached title; only the URL id does
        $todosContent = "# Todos\n\n"
            . "## [Renamed page](/apps/collectives/jf-protokolle-8/renamed-page-4127)\n\n"
            . "- [x] Buy milk\n";

        $file = $this->mockSourceFile("- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $captured = '';
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function (string $content) use (&$captured): void {
                $captured = $content;
            });

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
        $this->assertStringContainsString('- [x] Buy milk', $captured);
    }

    public function testResolvesSectionByFileIdFallbackUrl(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n"
            . "## [Meeting Notes](/apps/collectives/jf-protokolle-8/Meeting%20Notes?fileId=4127)\n\n"
            . "- [x] Buy milk\n";

        $file = $this->mockSourceFile("- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
    }

    public function testResolvesSectionByTitleWithoutLink(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Meeting Notes\n\n- [x] Buy milk\n";

        $file = $this->mockSourceFile("- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
    }

    public function testSkipsSectionWithUnknownHeading(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Unknown page\n\n- [x] Buy milk\n";

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(0, $count);
    }

    public function testSyncsWhenCachedLineDriftedButTextIsUnique(): void
    {
        // Cached line 3 no longer holds the checkbox, but the text occurs once
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 3, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Meeting Notes\n\n- [x] Buy milk\n";

        $sourceContent = "Intro\nMoved around\n\n- [ ] Buy milk\n";
        $file = $this->mockSourceFile($sourceContent);
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $captured = '';
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function (string $content) use (&$captured): void {
                $captured = $content;
            });

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
        $this->assertSame("Intro\nMoved around\n\n- [x] Buy milk\n", $captured);
    }

    public function testSkipsWhenCachedLineDriftedAndTextIsAmbiguous(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 2, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Meeting Notes\n\n- [x] Buy milk\n";

        // Cached line 2 no longer holds the checkbox and the text occurs twice
        $file = $this->mockSourceFile("- [ ] Buy milk\nMoved\n- [ ] Buy milk\n");
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $file->expects($this->never())->method('putContent');
        $this->logger->expects($this->once())->method('warning');

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(0, $count);
    }

    public function testChecksFirstDuplicateWhenIdenticalTextsTicked(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
            ['text' => 'Buy milk', 'checked' => false, 'line' => 4, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Meeting Notes\n\n- [x] Buy milk\n- [ ] Buy milk\n";

        $sourceContent = "- [ ] Buy milk\n\n\n- [ ] Buy milk\n";
        $file = $this->mockSourceFile($sourceContent);
        $this->rootFolder->method('getById')->with(4127)->willReturn([$file]);

        $captured = '';
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function (string $content) use (&$captured): void {
                $captured = $content;
            });

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(1, $count);
        $this->assertSame("- [x] Buy milk\n\n\n- [ ] Buy milk\n", $captured);
    }

    public function testSkipsWhenSourceFileNotFound(): void
    {
        $this->cacheWithPage('4127', 'Meeting Notes', [
            ['text' => 'Buy milk', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Buy milk'],
        ]);

        $todosContent = "# Todos\n\n## Meeting Notes\n\n- [x] Buy milk\n";

        $this->rootFolder->method('getById')->willReturn([]);
        $this->logger->expects($this->once())->method('warning');

        $count = $this->service->syncFromTodosPage($this->collectiveFolder, $todosContent);

        $this->assertSame(0, $count);
    }
}
