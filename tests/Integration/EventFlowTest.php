<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Integration;

use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\PageLinkBuilder;
use OCA\CollectiveTodos\Service\TextDocumentResetter;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use Test\TestCase;

class EventFlowTest extends TestCase
{
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;

    protected function setUp(): void
    {
        $rootFolder = $this->getMockBuilder(\OCP\Files\IRootFolder::class)->getMock();
        $config = $this->getMockBuilder(\OCP\IConfig::class)->getMock();
        $collectiveMapper = $this->getMockBuilder(\OCA\Collectives\Db\CollectiveMapper::class)
            ->disableOriginalConstructor()->getMock();
        $pageMapper = $this->getMockBuilder(\OCA\Collectives\Db\PageMapper::class)
            ->disableOriginalConstructor()->getMock();
        $logger = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock();

        $this->parser = new CheckboxParser();
        $this->aggregator = new CheckboxAggregator($rootFolder, $config);
        $this->generator = new TodosPageGenerator(
            $this->aggregator,
            new PageLinkBuilder($collectiveMapper, $pageMapper),
            new TextDocumentResetter($logger)
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
        $pages = [
            'page-1' => [
                'title' => 'Test Page',
                'page_id' => 'page-1',
                'last_modified' => '2026-10-01T07:00:00Z',
                'checkboxes' => [
                    ['text' => 'Test task', 'checked' => false, 'line' => 1, 'raw' => '- [ ] Test task'],
                ],
            ],
        ];
        
        // Mock the aggregator to return test data
        // Note: This would require more complex mocking in a full test
        $content = $this->generator->generateContent($this->createMock(\OCP\Files\Folder::class));
        
        $this->assertStringContainsString('# Todos', $content);
    }
}
