<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveMapper;
use OCP\IURLGenerator;

/**
 * Assemble the view data of the admin settings page: the instance-wide
 * defaults, the per-collective overrides, and the collective list. Submitted
 * values from a rejected save re-fill the fields they belong to.
 */
class PanelDataService
{
	private SettingsService $settings;
	private CollectiveMapper $collectiveMapper;
	private IURLGenerator $urlGenerator;

	public function __construct(
		SettingsService $settings,
		CollectiveMapper $collectiveMapper,
		IURLGenerator $urlGenerator
	) {
		$this->settings = $settings;
		$this->collectiveMapper = $collectiveMapper;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @param array<string, mixed> $submitted Raw form values from a rejected save
	 * @param list<string> $errors Validation or apply errors to display
	 *
	 * @return array{
	 *   form_action: string,
	 *   errors: list<string>,
	 *   defaults: array{todos_page_name: string, tree_position: string, max_checkboxes: int},
	 *   positions: array<string, string>,
	 *   collectives: list<array{id: int, name: string, todos_page_name: string, tree_position: string, max_checkboxes: string}>,
	 * }
	 */
	public function getPanelData(array $submitted = [], array $errors = []): array
	{
		$defaults = $this->settings->getDefaults();

		if (isset($submitted['default_todos_page_name']) && $submitted['default_todos_page_name'] !== '') {
			$defaults[SettingsService::KEY_TODOS_PAGE_NAME] = (string)$submitted['default_todos_page_name'];
		}
		if (isset($submitted['default_tree_position']) && $submitted['default_tree_position'] !== '') {
			$defaults[SettingsService::KEY_TREE_POSITION] = (string)$submitted['default_tree_position'];
		}
		if (isset($submitted['default_max_checkboxes']) && $submitted['default_max_checkboxes'] !== '') {
			$defaults[SettingsService::KEY_MAX_CHECKBOXES] = (int)$submitted['default_max_checkboxes'];
		}

		$collectives = [];
		foreach ($this->collectiveMapper->getAll() as $collective) {
			/** @var Collective $collective */
			if ($collective->getTrashTimestamp() !== null) {
				continue;
			}

			$collectiveId = (int)$collective->getId();
			$override = [
				'todos_page_name' => $this->settings->getOverride($collectiveId, SettingsService::KEY_TODOS_PAGE_NAME) ?? '',
				'tree_position' => $this->settings->getOverride($collectiveId, SettingsService::KEY_TREE_POSITION) ?? '',
				'max_checkboxes' => $this->settings->getOverride($collectiveId, SettingsService::KEY_MAX_CHECKBOXES) ?? '',
			];

			$submittedOverride = $submitted['override'][$collectiveId] ?? [];
			foreach (array_keys($override) as $key) {
				if (isset($submittedOverride[$key])) {
					$override[$key] = (string)$submittedOverride[$key];
				}
			}

			$collectives[] = [
				'id' => $collectiveId,
				'name' => $collective->getName(),
				'todos_page_name' => $override['todos_page_name'],
				'tree_position' => $override['tree_position'],
				'max_checkboxes' => $override['max_checkboxes'],
			];
		}

		usort($collectives, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

		return [
			'form_action' => $this->urlGenerator->linkToRoute('collectives_todos.Settings.save'),
			'errors' => $errors,
			'defaults' => [
				'todos_page_name' => $defaults[SettingsService::KEY_TODOS_PAGE_NAME],
				'tree_position' => $defaults[SettingsService::KEY_TREE_POSITION],
				'max_checkboxes' => (int)$defaults[SettingsService::KEY_MAX_CHECKBOXES],
			],
			'positions' => [
				SettingsService::POSITION_TOP => 'Always on top',
				SettingsService::POSITION_BOTTOM => 'Always on bottom',
				SettingsService::POSITION_ALPHABETICAL => 'Alphabetically',
			],
			'collectives' => $collectives,
		];
	}
}
