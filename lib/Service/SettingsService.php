<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\IConfig;

/**
 * Instance-wide defaults and per-collective overrides for the app's
 * configuration, stored in the app config (`oc_appconfig`).
 *
 * An override is unset when its key is absent or empty; resolution is
 * simply `override ?? default`. A null collective id resolves to the
 * defaults (e.g. CLI contexts without a collective id).
 */
class SettingsService
{
	public const APP_ID = 'collectives_todos';

	public const KEY_TODOS_PAGE_NAME = 'todos_page_name';
	public const KEY_TODOS_PAGE_EMOJI = 'todos_page_emoji';
	public const KEY_TREE_POSITION = 'tree_position';
	public const KEY_MAX_CHECKBOXES = 'max_checkboxes';

	public const POSITION_TOP = 'top';
	public const POSITION_BOTTOM = 'bottom';
	public const POSITION_ALPHABETICAL = 'alphabetical';

	public const POSITIONS = [
		self::POSITION_TOP,
		self::POSITION_BOTTOM,
		self::POSITION_ALPHABETICAL,
	];

	public const DEFAULT_TODOS_PAGE_NAME = 'Todos';

	private const DEFAULTS = [
		self::KEY_TODOS_PAGE_NAME => self::DEFAULT_TODOS_PAGE_NAME,
		self::KEY_TODOS_PAGE_EMOJI => '',
		self::KEY_TREE_POSITION => self::POSITION_TOP,
		self::KEY_MAX_CHECKBOXES => '0',
	];

	private IConfig $config;

	public function __construct(IConfig $config)
	{
		$this->config = $config;
	}

	/**
	 * @return array{todos_page_name: string, todos_page_emoji: string, tree_position: string, max_checkboxes: int}
	 */
	public function getDefaults(): array
	{
		return [
			self::KEY_TODOS_PAGE_NAME => $this->getDefault(self::KEY_TODOS_PAGE_NAME),
			self::KEY_TODOS_PAGE_EMOJI => $this->getDefault(self::KEY_TODOS_PAGE_EMOJI),
			self::KEY_TREE_POSITION => $this->getDefault(self::KEY_TREE_POSITION),
			self::KEY_MAX_CHECKBOXES => (int)$this->getDefault(self::KEY_MAX_CHECKBOXES),
		];
	}

	public function getDefault(string $key): string
	{
		$default = self::DEFAULTS[$key] ?? '';
		return $this->config->getAppValue(self::APP_ID, $key, $default);
	}

	public function setDefault(string $key, string $value): void
	{
		$this->setValidated($key, $value, null);
	}

	public function getOverride(int $collectiveId, string $key): ?string
	{
		$value = $this->config->getAppValue(self::APP_ID, $this->overrideKey($collectiveId, $key), '');
		return $value === '' ? null : $value;
	}

	public function setOverride(int $collectiveId, string $key, string $value): void
	{
		$this->setValidated($key, $value, $collectiveId);
	}

	public function clearOverride(int $collectiveId, string $key): void
	{
		$this->config->deleteAppValue(self::APP_ID, $this->overrideKey($collectiveId, $key));
	}

	public function resolve(?int $collectiveId, string $key): string
	{
		if ($collectiveId !== null) {
			$override = $this->getOverride($collectiveId, $key);
			if ($override !== null) {
				return $override;
			}
		}
		return $this->getDefault($key);
	}

	public function resolveInt(?int $collectiveId, string $key): int
	{
		return (int)$this->resolve($collectiveId, $key);
	}

	public function resolveTodosPageFilename(?int $collectiveId): string
	{
		return self::normalizePageName($this->resolve($collectiveId, self::KEY_TODOS_PAGE_NAME)) . '.md';
	}

	/**
	 * Sanitize a page name entered in the settings: trim, strip path
	 * separators and control characters, drop a .md suffix, collapse
	 * inner whitespace. Throws when nothing usable remains.
	 */
	public static function normalizePageName(string $raw): string
	{
		$name = str_replace(['/', '\\'], '', $raw);
		$name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
		$name = trim($name);
		$name = preg_replace('/\s+/', ' ', $name) ?? '';

		if (preg_match('/\.md$/i', $name)) {
			$name = substr($name, 0, -3);
		}

		$name = trim($name);

		if ($name === '') {
			throw new \InvalidArgumentException('The Todos page name must not be empty.');
		}
		return $name;
	}

	public static function normalizePosition(string $raw): string
	{
		if (!in_array($raw, self::POSITIONS, true)) {
			throw new \InvalidArgumentException('The tree position must be one of: ' . implode(', ', self::POSITIONS) . '.');
		}
		return $raw;
	}

	public static function normalizeMaxCheckboxes(string $raw): int
	{
		$raw = trim($raw);
		if ($raw === '') {
			return 0;
		}
		if (!ctype_digit($raw)) {
			throw new \InvalidArgumentException('The maximum checkboxes value must be a non-negative integer.');
		}
		return (int)$raw;
	}

	/**
	 * Validate a Todos page emoji the same way the Collectives app validates
	 * page emoji (single emoji grapheme, max 8 characters). Empty means no
	 * emoji.
	 */
	public static function normalizePageEmoji(string $raw): string
	{
		$raw = trim($raw);
		try {
			\OCA\Collectives\Service\EmojiHelper::assertValid($raw === '' ? null : $raw);
		} catch (\OCA\Collectives\Service\UnprocessableEntityException $e) {
			throw new \InvalidArgumentException($e->getMessage(), 0, $e);
		}
		return $raw;
	}

	private function setValidated(string $key, string $value, ?int $collectiveId): void
	{
		$validated = match ($key) {
			self::KEY_TODOS_PAGE_NAME => self::normalizePageName($value),
			self::KEY_TODOS_PAGE_EMOJI => self::normalizePageEmoji($value),
			self::KEY_TREE_POSITION => self::normalizePosition($value),
			self::KEY_MAX_CHECKBOXES => (string)self::normalizeMaxCheckboxes($value),
			default => throw new \InvalidArgumentException('Unknown config key: ' . $key),
		};

		if ($collectiveId === null) {
			$this->config->setAppValue(self::APP_ID, $key, $validated);
			return;
		}
		$this->config->setAppValue(self::APP_ID, $this->overrideKey($collectiveId, $key), $validated);
	}

	private function overrideKey(int $collectiveId, string $key): string
	{
		return 'collective.' . $collectiveId . '.' . $key;
	}
}
