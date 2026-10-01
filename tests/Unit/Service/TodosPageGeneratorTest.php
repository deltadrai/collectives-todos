<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\PageLinkBuilder;
use OCA\CollectiveTodos\Service\TextDocumentResetter;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class TodosPageGeneratorTest extends TestCase
{
    private TodosPageGenerator $generator;
    private CheckboxAggregator $aggregator;
    private PageLinkBuilder $linkBuilder;
    private TextDocumentResetter $textResetter;
    private Folder $collectiveFolder;

    protected function setUp(): void
    {
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->linkBuilder = $this->createMock(PageLinkBuilder::class);
        $this->textResetter = $this->createMock(TextDocumentResetter::class);
        $this->collectiveFolder = $this->createMock(Folder::class);
        $this->generator = new TodosPageGenerator($this->aggregator, $this->linkBuilder, $this->textResetter);
    }

    public function testGenerateContentWithNoCheckboxes(): void
    {
        $this->aggregator->method('getAllCheckboxes')->willReturn([]);
        
        $content = $this->generator->generateContent($this->collectiveFolder);
        
        $this->assertStringContainsString('# Todos', $content);
        $this->assertStringContainsString('No tasks found', $content);
    }

    public function testGenerateContentWithCheckboxes(): void
    {
        $pages = [
            'page-1' => [
                'title' => 'Meeting Notes',
                'page_id' => 'page-1',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Prepare agenda', 'checked' => false, 'line' => 5, 'raw' => '- [ ] Prepare agenda'],
                    ['text' => 'Send follow-up email', 'checked' => true, 'line' => 8, 'raw' => '- [x] Send follow-up email'],
                ],
            ],
        ];
        
        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        
        $content = $this->generator->generateContent($this->collectiveFolder);
        
        $this->assertStringContainsString('# Todos', $content);
        $this->assertStringContainsString('## Meeting Notes', $content);
        $this->assertStringContainsString('- [ ] Prepare agenda', $content);
        $this->assertStringContainsString('- [x] Send follow-up email', $content);
    }

    public function testGenerateContentWithMultiplePages(): void
    {
        $pages = [
            'page-1' => [
                'title' => 'Page One',
                'page_id' => 'page-1',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 1', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Task 1'],
                ],
            ],
            'page-2' => [
                'title' => 'Page Two',
                'page_id' => 'page-2',
                'last_modified' => '2026-10-01T08:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 2', 'checked' => true, 'line' => 1, 'raw' => '- [x] Task 2'],
                ],
            ],
        ];
        
        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        
        $content = $this->generator->generateContent($this->collectiveFolder);
        
        $this->assertStringContainsString('## Page One', $content);
        $this->assertStringContainsString('## Page Two', $content);
        $this->assertStringContainsString('- [ ] Task 1', $content);
        $this->assertStringContainsString('- [x] Task 2', $content);
    }

    public function testGenerateContentWithDuplicateTitles(): void
    {
        $pages = [
            'page-1' => [
                'title' => 'Notes',
                'page_id' => 'page-1',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 1', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Task 1'],
                ],
            ],
            'page-2' => [
                'title' => 'Notes',
                'page_id' => 'page-2',
                'last_modified' => '2026-10-01T08:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 2', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Task 2'],
                ],
            ],
        ];
        
        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        
        $content = $this->generator->generateContent($this->collectiveFolder);
        
        // Duplicate titles should have page_id appended
        $this->assertStringContainsString('## Notes (page-1)', $content);
        $this->assertStringContainsString('## Notes (page-2)', $content);
    }

    public function testGetFilename(): void
    {
        $this->assertSame('Todos.md', $this->generator->getFilename());
    }

    public function testGenerateContentWithLinkedHeading(): void
    {
        $pages = [
            '4127' => [
                'title' => 'Meeting Notes',
                'page_id' => '4127',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Prepare agenda', 'checked' => false, 'line' => 5, 'raw' => '- [ ] Prepare agenda'],
                ],
            ],
        ];

        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        $this->aggregator->method('getCollectiveId')->willReturn(8);
        $this->linkBuilder->method('getPageUrl')
            ->with(8, '4127', 'Meeting Notes')
            ->willReturn('/apps/collectives/jf-protokolle-8/meeting-notes-4127');

        $content = $this->generator->generateContent($this->collectiveFolder);

        $this->assertStringContainsString(
            '## [Meeting Notes](/apps/collectives/jf-protokolle-8/meeting-notes-4127)',
            $content
        );
    }

    public function testGenerateContentFallsBackToPlainHeadingWithoutUrl(): void
    {
        $pages = [
            '4127' => [
                'title' => 'Meeting Notes',
                'page_id' => '4127',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Prepare agenda', 'checked' => false, 'line' => 5, 'raw' => '- [ ] Prepare agenda'],
                ],
            ],
        ];

        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        $this->aggregator->method('getCollectiveId')->willReturn(8);
        $this->linkBuilder->method('getPageUrl')->willReturn(null);

        $content = $this->generator->generateContent($this->collectiveFolder);

        $this->assertStringContainsString('## Meeting Notes', $content);
    }

    public function testGenerateContentEscapesBracketsInLinkText(): void
    {
        $pages = [
            '4127' => [
                'title' => 'Notes [draft]',
                'page_id' => '4127',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Prepare agenda', 'checked' => false, 'line' => 5, 'raw' => '- [ ] Prepare agenda'],
                ],
            ],
        ];

        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        $this->aggregator->method('getCollectiveId')->willReturn(8);
        $this->linkBuilder->method('getPageUrl')
            ->willReturn('/apps/collectives/jf-protokolle-8/notes-draft-4127');

        $content = $this->generator->generateContent($this->collectiveFolder);

        $this->assertStringContainsString(
            '## [Notes \[draft\]](/apps/collectives/jf-protokolle-8/notes-draft-4127)',
            $content
        );
    }

    public function testGenerateContentWithDuplicateTitlesKeepsSuffixInLinkText(): void
    {
        $pages = [
            'page-1' => [
                'title' => 'Notes',
                'page_id' => 'page-1',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 1', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Task 1'],
                ],
            ],
            'page-2' => [
                'title' => 'Notes',
                'page_id' => 'page-2',
                'last_modified' => '2026-10-01T08:00:00Z',
                'checkboxes' => [
                    ['text' => 'Task 2', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Task 2'],
                ],
            ],
        ];

        $this->aggregator->method('getAllCheckboxes')->willReturn($pages);
        $this->aggregator->method('getCollectiveId')->willReturn(8);
        $this->linkBuilder->method('getPageUrl')->willReturnCallback(
            static fn (int $collectiveId, string $pageId, string $title) => '/apps/collectives/jf-protokolle-8/notes-' . $pageId
        );

        $content = $this->generator->generateContent($this->collectiveFolder);

        $this->assertStringContainsString('## [Notes (page-1)](/apps/collectives/jf-protokolle-8/notes-page-1)', $content);
        $this->assertStringContainsString('## [Notes (page-2)](/apps/collectives/jf-protokolle-8/notes-page-2)', $content);
    }

    public function testRegenerateTodosPageResetsTextDocumentState(): void
    {
        $todosFile = $this->createMock(File::class);
        $todosFile->method('getId')->willReturn(4148);

        $this->aggregator->method('getAllCheckboxes')->willReturn([]);
        $this->collectiveFolder->method('nodeExists')->willReturn(true);
        $this->collectiveFolder->method('get')->willReturn($todosFile);

        $this->textResetter->expects($this->once())
            ->method('resetForFile')
            ->with(4148);

        $this->generator->regenerateTodosPage($this->collectiveFolder);
    }
}
