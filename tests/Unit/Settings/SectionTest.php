<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Settings;

use OCA\CollectiveTodos\Settings\Section;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SectionTest extends TestCase
{
	public function testSectionMetadata(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);
		$urlGenerator = $this->createMock(IURLGenerator::class);

		$section = new Section($urlGenerator, $l10n);

		$this->assertSame('collectives_todos', $section->getID());
		$this->assertSame('Collectives Todos', $section->getName());
		$this->assertSame(80, $section->getPriority());
		$this->assertSame('', $section->getIcon());
	}
}
