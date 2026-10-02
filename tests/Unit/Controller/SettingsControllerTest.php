<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Controller;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveMapper;
use OCA\CollectiveTodos\Controller\SettingsController;
use OCA\CollectiveTodos\Service\PanelDataService;
use OCA\CollectiveTodos\Service\SettingsApplierService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase
{
	private SettingsService $settings;
	private SettingsApplierService $applier;
	private PanelDataService $panelData;
	private CollectiveMapper $collectiveMapper;
	private IURLGenerator $urlGenerator;
	private SettingsController $controller;

	/** @var array<string, mixed> posted form values, keyed by param name */
	private array $params = [];

	/** @var array<int, string> resolved Todos filename per collective id, mutable by setOverride */
	private array $resolvedFilenames = [];

	/** @var array<int, string> resolved tree position per collective id, mutable by setOverride */
	private array $resolvedPositions = [];

	/** @var string default tree position, mutable by setDefault */
	private string $defaultPosition = 'top';

	protected function setUp(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default
		);

		$this->settings = $this->createMock(SettingsService::class);
		$this->applier = $this->createMock(SettingsApplierService::class);
		$this->panelData = $this->createMock(PanelDataService::class);
		$this->collectiveMapper = $this->getMockBuilder(CollectiveMapper::class)
			->disableOriginalConstructor()->getMock();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->settings->method('resolveTodosPageFilename')
			->willReturnCallback(function (?int $collectiveId) {
				if ($collectiveId === null) {
					return 'Todos.md';
				}
				return $this->resolvedFilenames[$collectiveId] ?? 'Todos.md';
			});
		$this->settings->method('resolve')
			->willReturnCallback(function (?int $collectiveId, string $key) {
				if ($key !== SettingsService::KEY_TREE_POSITION) {
					return null;
				}
				if ($collectiveId === null) {
					return $this->defaultPosition;
				}
				return $this->resolvedPositions[$collectiveId] ?? $this->defaultPosition;
			});
		$this->settings->method('setDefault')->willReturnCallback(
			function (string $key, string $value): void {
				if ($key === SettingsService::KEY_TREE_POSITION) {
					$this->defaultPosition = $value;
				}
			}
		);

		$this->urlGenerator->method('linkToRoute')
			->willReturnCallback(fn (string $route, array $args = []) => $route . ':' . json_encode($args));

		$this->controller = new SettingsController(
			'collectives_todos',
			$request,
			$this->settings,
			$this->applier,
			$this->panelData,
			$this->collectiveMapper,
			$this->urlGenerator
		);
	}

	private function collective(int $id, ?int $trashTimestamp = null): Collective
	{
		$collective = new Collective();
		$collective->setId($id);
		$collective->setName('Collective ' . $id);
		if ($trashTimestamp !== null) {
			$collective->setTrashTimestamp($trashTimestamp);
		}
		return $collective;
	}

	public function testSaveSetsDefaultsAndRedirects(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'bottom',
			'default_max_checkboxes' => '10',
		];
		$this->collectiveMapper->method('getAll')->willReturn([]);

		$setDefaultCalls = [];
		$this->settings->method('setDefault')->willReturnCallback(
			function (string $key, string $value) use (&$setDefaultCalls): void {
				$setDefaultCalls[] = [$key, $value];
			}
		);
		$this->applier->expects($this->never())->method('applyToCollective');

		$response = $this->controller->save();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'settings.adminsettings.index:{"section":"collectives_todos"}',
			$response->getRedirectUrl()
		);
		$this->assertSame([
			[SettingsService::KEY_TODOS_PAGE_NAME, 'Tasks'],
			[SettingsService::KEY_TREE_POSITION, 'bottom'],
			[SettingsService::KEY_MAX_CHECKBOXES, '10'],
		], $setDefaultCalls);
	}

	public function testSaveRejectsInvalidDefaults(): void
	{
		$this->params = [
			'default_todos_page_name' => '',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
		];

		$this->settings->expects($this->never())->method('setDefault');
		$this->applier->expects($this->never())->method('applyToCollective');

		$expectedParams = ['form_action' => 'x', 'errors' => ['err']];
		$this->panelData->expects($this->once())->method('getPanelData')
			->willReturnCallback(function (array $submitted, array $errors) use ($expectedParams) {
				$this->assertNotEmpty($errors);
				$this->assertSame('', $submitted['default_todos_page_name']);
				return $expectedParams;
			});

		$response = $this->controller->save();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('admin', $response->getTemplateName());
		$this->assertSame($expectedParams, $response->getParams());
	}

	public function testSaveAppliesOverrides(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
			'override' => [
				6 => ['todos_page_name' => '', 'tree_position' => '', 'max_checkboxes' => ''],
				7 => ['todos_page_name' => 'Aufgaben', 'tree_position' => 'bottom', 'max_checkboxes' => '100'],
			],
		];
		$this->collectiveMapper->method('getAll')->willReturn([]);

		$clearOverrideCalls = [];
		$this->settings->method('clearOverride')->willReturnCallback(
			function (int $collectiveId, string $key) use (&$clearOverrideCalls): void {
				$clearOverrideCalls[] = [$collectiveId, $key];
			}
		);
		$setOverrideCalls = [];
		$this->settings->method('setOverride')->willReturnCallback(
			function (int $collectiveId, string $key, string $value) use (&$setOverrideCalls): void {
				$setOverrideCalls[] = [$collectiveId, $key, $value];
			}
		);

		$this->controller->save();

		$this->assertSame([
			[6, SettingsService::KEY_TODOS_PAGE_NAME],
			[6, SettingsService::KEY_TREE_POSITION],
			[6, SettingsService::KEY_MAX_CHECKBOXES],
		], $clearOverrideCalls);
		$this->assertSame([
			[7, SettingsService::KEY_TODOS_PAGE_NAME, 'Aufgaben'],
			[7, SettingsService::KEY_TREE_POSITION, 'bottom'],
			[7, SettingsService::KEY_MAX_CHECKBOXES, '100'],
		], $setOverrideCalls);
	}

	public function testSaveAppliesSideEffectsOnlyToAffectedCollectives(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
			'override' => [
				6 => ['todos_page_name' => 'Aufgaben', 'tree_position' => '', 'max_checkboxes' => ''],
			],
		];
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(6),
			$this->collective(7),
		]);

		$this->settings->method('setOverride')->willReturnCallback(
			function (int $collectiveId, string $key, string $value): void {
				if ($collectiveId === 6 && $key === SettingsService::KEY_TODOS_PAGE_NAME) {
					$this->resolvedFilenames[6] = 'Aufgaben.md';
				}
			}
		);

		$this->applier->expects($this->once())
			->method('applyToCollective')
			->with(6, 'Todos.md');

		$this->controller->save();
	}

	public function testApplierErrorsRenderPanelWithErrors(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
			'override' => [
				6 => ['todos_page_name' => 'Aufgaben', 'tree_position' => '', 'max_checkboxes' => ''],
			],
		];
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(6),
		]);

		$this->settings->method('setOverride')->willReturnCallback(
			function (int $collectiveId, string $key, string $value): void {
				if ($collectiveId === 6 && $key === SettingsService::KEY_TODOS_PAGE_NAME) {
					$this->resolvedFilenames[6] = 'Aufgaben.md';
				}
			}
		);
		$this->applier->method('applyToCollective')->willReturn('rename failed');

		$expectedParams = ['form_action' => 'x', 'errors' => ['Collective 6: rename failed']];
		$this->panelData->expects($this->once())->method('getPanelData')
			->with([], ['Collective 6: rename failed'])
			->willReturn($expectedParams);

		$response = $this->controller->save();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame($expectedParams, $response->getParams());
	}

	public function testSaveRejectsInvalidOverride(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
			'override' => [
				6 => ['todos_page_name' => 'Aufgaben', 'tree_position' => 'middle', 'max_checkboxes' => ''],
			],
		];

		// Nothing must be written, neither defaults nor overrides
		$this->settings->expects($this->never())->method('setDefault');
		$this->settings->expects($this->never())->method('setOverride');
		$this->settings->expects($this->never())->method('clearOverride');
		$this->applier->expects($this->never())->method('applyToCollective');

		$expectedParams = ['form_action' => 'x', 'errors' => ['err']];
		$this->panelData->expects($this->once())->method('getPanelData')
			->willReturnCallback(function (array $submitted, array $errors) use ($expectedParams) {
				$this->assertNotEmpty($errors);
				return $expectedParams;
			});

		$response = $this->controller->save();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame($expectedParams, $response->getParams());
	}

	public function testSaveAppliesSideEffectsWhenPositionChanged(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'top',
			'default_max_checkboxes' => '0',
			'override' => [
				6 => ['todos_page_name' => '', 'tree_position' => 'bottom', 'max_checkboxes' => ''],
			],
		];
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(6),
		]);

		$this->settings->method('setOverride')->willReturnCallback(
			function (int $collectiveId, string $key, string $value): void {
				if ($collectiveId === 6 && $key === SettingsService::KEY_TREE_POSITION) {
					$this->resolvedPositions[6] = 'bottom';
				}
			}
		);

		$this->applier->expects($this->once())
			->method('applyToCollective')
			->with(6, 'Todos.md');

		$response = $this->controller->save();

		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	public function testSaveAppliesSideEffectsWhenDefaultPositionChanged(): void
	{
		$this->params = [
			'default_todos_page_name' => 'Tasks',
			'default_tree_position' => 'bottom',
			'default_max_checkboxes' => '0',
		];
		$this->collectiveMapper->method('getAll')->willReturn([
			$this->collective(6),
		]);

		$this->applier->expects($this->once())
			->method('applyToCollective')
			->with(6, 'Todos.md');

		$this->controller->save();
	}
}
