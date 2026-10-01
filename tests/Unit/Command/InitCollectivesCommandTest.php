<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Command;

use OCA\CollectiveTodos\Command\InitCollectivesCommand;
use OCA\CollectiveTodos\Service\CheckboxParser;
use OCA\CollectiveTodos\Service\CheckboxAggregator;
use OCA\CollectiveTodos\Service\TodosPageGenerator;
use OCP\Files\File;
use OCP\Files\Folder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

class InitCollectivesCommandTest extends TestCase
{
    private InitCollectivesCommand $command;
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private TodosPageGenerator $generator;

    protected function setUp(): void
    {
        $this->parser = $this->createMock(CheckboxParser::class);
        $this->aggregator = $this->createMock(CheckboxAggregator::class);
        $this->generator = $this->createMock(TodosPageGenerator::class);

        $this->command = new InitCollectivesCommand(
            $this->parser,
            $this->aggregator,
            $this->generator
        );
    }

    public function testCommandIsConfiguredCorrectly(): void
    {
        $this->assertSame('collectives_todos:init', $this->command->getName());
        $this->assertStringContainsString('Initialize existing collectives', $this->command->getDescription());
    }

    public function testExecuteScansPagesAndRegenerates(): void
    {
        $application = new Application();
        $application->add($this->command);

        $command = $application->find('collectives_todos:init');
        $commandTester = new CommandTester($command);

        $collectiveFolder = $this->createMock(Folder::class);
        $page = $this->createMock(File::class);

        $collectiveFolder->method('getName')->willReturn('JF Protokolle');
        $collectiveFolder->method('getDirectoryListing')->willReturn([$page]);
        $page->method('getName')->willReturn('Readme.md');
        $page->method('getMimeType')->willReturn('text/markdown');
        $page->method('getId')->willReturn(42);
        $page->method('getContent')->willReturn('# Page' . "\n" . '- [ ] A task');

        $this->aggregator->method('getFolder')->with('8')->willReturn($collectiveFolder);
        $this->aggregator->method('derivePageTitle')->willReturn('Page Title');
        $this->parser->method('parse')->willReturn([['text' => 'A task', 'checked' => false, 'line' => 2, 'raw' => '- [ ] A task']]);

        $this->aggregator->expects($this->once())->method('clear')->with($collectiveFolder);
        $this->aggregator->expects($this->once())->method('updatePageCheckboxes')
            ->with($collectiveFolder, '42', 'Page Title', $this->anything());
        $this->generator->expects($this->once())->method('regenerateTodosPage')->with($collectiveFolder);

        $commandTester->execute(['collectives-id' => '8']);

        $this->assertStringContainsString('Initialized collectives: JF Protokolle', $commandTester->getDisplay());
        $this->assertStringContainsString('Scanned 1 pages, found 1 checkboxes', $commandTester->getDisplay());
    }

    public function testExecuteFailsOnUnknownCollective(): void
    {
        $application = new Application();
        $application->add($this->command);

        $command = $application->find('collectives_todos:init');
        $commandTester = new CommandTester($command);

        $this->aggregator->method('getFolder')->willThrowException(new \RuntimeException('not found'));

        $exitCode = $commandTester->execute(['collectives-id' => '999']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not find collectives folder', $commandTester->getDisplay());
    }
}
