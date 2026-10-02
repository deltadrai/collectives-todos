<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase
{
	private IConfig $config;
	private SettingsService $service;

	protected function setUp(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$this->service = new SettingsService($this->config);
	}

	public function testDefaultsWhenNothingSet(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$defaults = $this->service->getDefaults();

		$this->assertSame('Todos', $defaults['todos_page_name']);
		$this->assertSame('top', $defaults['tree_position']);
		$this->assertSame(0, $defaults['max_checkboxes']);
		$this->assertSame('Todos', $this->service->resolve(8, SettingsService::KEY_TODOS_PAGE_NAME));
		$this->assertSame('Todos.md', $this->service->resolveTodosPageFilename(8));
	}

	public function testSetAndGetDefault(): void
	{
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('collectives_todos', 'tree_position', 'bottom');
		$this->config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				return $key === 'tree_position' ? 'bottom' : $default;
			});

		$this->service->setDefault(SettingsService::KEY_TREE_POSITION, 'bottom');

		$this->assertSame('bottom', $this->service->getDefault(SettingsService::KEY_TREE_POSITION));
		$this->assertSame('bottom', $this->service->resolve(42, SettingsService::KEY_TREE_POSITION));
	}

	public function testOverrideWinsOverDefault(): void
	{
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('collectives_todos', 'collective.8.todos_page_name', 'Aufgaben');
		$this->config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				return $key === 'collective.8.todos_page_name' ? 'Aufgaben' : $default;
			});

		$this->service->setOverride(8, SettingsService::KEY_TODOS_PAGE_NAME, 'Aufgaben');

		$this->assertSame('Aufgaben', $this->service->resolve(8, SettingsService::KEY_TODOS_PAGE_NAME));
		$this->assertSame('Todos', $this->service->resolve(9, SettingsService::KEY_TODOS_PAGE_NAME));
		$this->assertSame('Aufgaben.md', $this->service->resolveTodosPageFilename(8));
	}

	public function testClearOverrideFallsBackToDefault(): void
	{
		$stored = 'bottom';
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $key === 'collective.8.tree_position' ? $stored : $default;
			});
		$this->config->expects($this->once())
			->method('deleteAppValue')
			->with('collectives_todos', 'collective.8.tree_position')
			->willReturnCallback(static function () use (&$stored): void {
				$stored = '';
			});

		$this->assertSame('bottom', $this->service->getOverride(8, SettingsService::KEY_TREE_POSITION));
		$this->assertSame('bottom', $this->service->resolve(8, SettingsService::KEY_TREE_POSITION));

		$this->service->clearOverride(8, SettingsService::KEY_TREE_POSITION);

		$this->assertNull($this->service->getOverride(8, SettingsService::KEY_TREE_POSITION));
		$this->assertSame('top', $this->service->resolve(8, SettingsService::KEY_TREE_POSITION));
	}

	public function testNullCollectiveIdUsesDefaults(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);
		$this->assertSame('Todos', $this->service->resolve(null, SettingsService::KEY_TODOS_PAGE_NAME));
		$this->assertSame('top', $this->service->resolve(null, SettingsService::KEY_TREE_POSITION));
		$this->assertSame(0, $this->service->resolveInt(null, SettingsService::KEY_MAX_CHECKBOXES));
		$this->assertSame('Todos.md', $this->service->resolveTodosPageFilename(null));
	}

	public function testNormalizePageNameRejectsEmpty(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageName('');
	}

	public function testNormalizePageNameRejectsWhitespaceOnly(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageName('   ');
	}

	public function testNormalizePageNameRejectsPathOnlyInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageName('///');
	}

	public function testNormalizePageNameStripsPathCharacters(): void
	{
		$this->assertSame('foobar', SettingsService::normalizePageName('foo/bar.md'));
		$this->assertSame('Meine Aufgaben', SettingsService::normalizePageName("  Meine \nAufgaben.md  "));
		$this->assertSame('Tasks', SettingsService::normalizePageName('Tasks.md'));
		$this->assertSame('Backslash', SettingsService::normalizePageName('Back\\slash'));
	}

	public function testNormalizePositionRejectsUnknown(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePosition('middle');
	}

	public function testNormalizePositionAcceptsEnum(): void
	{
		$this->assertSame('top', SettingsService::normalizePosition('top'));
		$this->assertSame('bottom', SettingsService::normalizePosition('bottom'));
		$this->assertSame('alphabetical', SettingsService::normalizePosition('alphabetical'));
	}

	public function testNormalizeMaxCheckboxesRejectsNegative(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeMaxCheckboxes('-5');
	}

	public function testNormalizeMaxCheckboxesRejectsNonNumeric(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeMaxCheckboxes('abc');
	}

	public function testNormalizeMaxCheckboxesRejectsFloat(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeMaxCheckboxes('1.5');
	}

	public function testNormalizeMaxCheckboxesAcceptsValidInput(): void
	{
		$this->assertSame(0, SettingsService::normalizeMaxCheckboxes(''));
		$this->assertSame(0, SettingsService::normalizeMaxCheckboxes('0'));
		$this->assertSame(500, SettingsService::normalizeMaxCheckboxes('500'));
	}

	public function testSetDefaultRejectsInvalid(): void
	{
		$this->config->expects($this->never())->method('setAppValue');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setDefault(SettingsService::KEY_MAX_CHECKBOXES, '-5');
	}

	public function testNormalizePageEmojiAcceptsEmptyAndSingleEmoji(): void
	{
		$this->assertSame('', SettingsService::normalizePageEmoji(''));
		$this->assertSame('', SettingsService::normalizePageEmoji('   '));
		$this->assertSame('✅', SettingsService::normalizePageEmoji(' ✅ '));
		$this->assertSame('🐛', SettingsService::normalizePageEmoji('🐛'));
	}

	public function testNormalizePageEmojiRejectsPlainText(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageEmoji('abc');
	}

	public function testNormalizePageEmojiRejectsMultipleGraphemes(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageEmoji('✅✅');
	}

	public function testNormalizePageEmojiRejectsControlCharacters(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizePageEmoji("✅\x01");
	}

	public function testDefaultsIncludeTodosPageEmoji(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$defaults = $this->service->getDefaults();

		$this->assertSame('', $defaults['todos_page_emoji']);
		$this->assertSame('', $this->service->resolve(8, SettingsService::KEY_TODOS_PAGE_EMOJI));
	}

	public function testEnabledByDefault(): void
	{
		$this->config->method('getAppValue')
			->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$this->assertTrue($this->service->isEnabled(8));
		$this->assertTrue($this->service->isEnabled(null));
	}

	public function testDisabledOverrideDisablesCollective(): void
	{
		$stored = '';
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $key === 'collective.8.todos_page_enabled' ? $stored : $default;
			});
		$this->config->method('setAppValue')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): void {
				if ($key === 'collective.8.todos_page_enabled') {
					$stored = $value;
				}
			});

		$this->service->setOverride(8, SettingsService::KEY_ENABLED, SettingsService::VALUE_DISABLED);

		$this->assertFalse($this->service->isEnabled(8));
		$this->assertTrue($this->service->isEnabled(9));

		$this->service->setOverride(8, SettingsService::KEY_ENABLED, SettingsService::VALUE_ENABLED);

		$this->assertTrue($this->service->isEnabled(8));
	}

	public function testNormalizeEnabledRejectsOtherValues(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeEnabled('yes');
	}

	public function testEnabledKeyDoesNotCollideWithAppFlag(): void
	{
		// Nextcloud stores the app's own enable state under the 'enabled'
		// key of the same appconfig namespace - writing our default there
		// would disable the whole app
		$this->assertNotSame('enabled', SettingsService::KEY_ENABLED);
	}
}
