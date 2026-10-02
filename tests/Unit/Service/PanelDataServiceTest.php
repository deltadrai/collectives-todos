<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveMapper;
use OCA\CollectiveTodos\Service\PanelDataService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PanelDataServiceTest extends TestCase
{
	private SettingsService $settings;
	private CollectiveMapper $collectiveMapper;
	private IURLGenerator $urlGenerator;
	private PanelDataService $service;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getDefaults')->willReturn([
			SettingsService::KEY_TODOS_PAGE_NAME => 'Todos',
			SettingsService::KEY_TREE_POSITION => 'top',
			SettingsService::KEY_MAX_CHECKBOXES => 0,
		]);
		$this->collectiveMapper = $this->getMockBuilder(CollectiveMapper::class)
			->disableOriginalConstructor()->getMock();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new PanelDataService($this->settings, $this->collectiveMapper, $this->urlGenerator);
	}

	private function collective(int $id, ?int $trashTimestamp = null): Collective
	{
		$collective = new Collective();
		$collective->setId($id);
		if ($trashTimestamp !== null) {
			$collective->setTrashTimestamp($trashTimestamp);
		}
		return $collective;
	}

	private function wireCollectiveNames(array $names): void
	{
		$this->collectiveMapper->method('idToName')->willReturnCallback(
			static fn (int $id, ?string $userId = null, bool $super = false) => $names[$id] ?? 'Collective ' . $id
		);
	}

	public function testPanelDataShape(): void
	{
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(9),
			$this->collective(8),
			$this->collective(10, 1700000000),
		]);
		// Collectives resolve their display names through the Circles app
		$this->wireCollectiveNames([8 => 'Anton', 9 => 'Zebra']);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/collectives_todos/settings');

		$data = $this->service->getPanelData();

		$this->assertSame('/apps/collectives_todos/settings', $data['form_action']);
		$this->assertSame([], $data['errors']);
		$this->assertSame('Todos', $data['defaults']['todos_page_name']);
		$this->assertSame('top', $data['defaults']['tree_position']);
		$this->assertSame(0, $data['defaults']['max_checkboxes']);
		$this->assertSame(['top' => 'Always on top', 'bottom' => 'Always on bottom', 'alphabetical' => 'Alphabetically'], $data['positions']);

		// trashed collective excluded, remaining sorted by name
		$this->assertCount(2, $data['collectives']);
		$this->assertSame(8, $data['collectives'][0]['id']);
		$this->assertSame('Anton', $data['collectives'][0]['name']);
		$this->assertSame(9, $data['collectives'][1]['id']);
		$this->assertSame('Zebra', $data['collectives'][1]['name']);
		$this->assertSame('', $data['collectives'][0]['todos_page_name']);
		$this->assertSame('', $data['collectives'][0]['tree_position']);
		$this->assertSame('', $data['collectives'][0]['max_checkboxes']);
	}

	public function testOverridesPrefilled(): void
	{
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(8),
			$this->collective(9),
		]);
		$this->wireCollectiveNames([8 => 'Anton', 9 => 'Zebra']);
		$this->settings->method('getOverride')->willReturnCallback(
			fn (int $collectiveId, string $key) => $collectiveId === 8 && $key === SettingsService::KEY_TODOS_PAGE_NAME ? 'Aufgaben' : null
		);

		$data = $this->service->getPanelData();

		$this->assertSame('Aufgaben', $data['collectives'][0]['todos_page_name']);
		$this->assertSame('', $data['collectives'][1]['todos_page_name']);
	}

	public function testSubmittedValuesOverrideDisplay(): void
	{
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(8),
			$this->collective(9),
		]);
		$this->wireCollectiveNames([8 => 'Anton', 9 => 'Zebra']);

		$data = $this->service->getPanelData([
			'default_tree_position' => 'bottom',
			'default_todos_page_name' => 'Tasks',
			'default_max_checkboxes' => '10',
			'override' => [
				9 => ['tree_position' => 'alphabetical'],
			],
		], ['something failed']);

		$this->assertSame(['something failed'], $data['errors']);
		$this->assertSame('bottom', $data['defaults']['tree_position']);
		$this->assertSame('Tasks', $data['defaults']['todos_page_name']);
		$this->assertSame(10, $data['defaults']['max_checkboxes']);
		$this->assertSame('alphabetical', $data['collectives'][1]['tree_position']);
		$this->assertSame('Anton', $data['collectives'][0]['name']);
	}

	public function testFallsBackWhenNameUnresolvable(): void
	{
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(8),
		]);
		$this->collectiveMapper->method('idToName')
			->willThrowException(new \OCP\AppFramework\QueryException('circle gone'));

		$data = $this->service->getPanelData();

		$this->assertSame('Collective 8', $data['collectives'][0]['name']);
	}
}
