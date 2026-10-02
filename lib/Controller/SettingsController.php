<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Controller;

use OCA\CollectiveTodos\Service\PanelDataService;
use OCA\CollectiveTodos\Service\SettingsApplierService;
use OCA\CollectiveTodos\Service\SettingsService;
use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Save endpoint of the admin settings page. Validates first and writes
 * nothing on invalid input; then writes defaults and per-collective
 * overrides and applies the side effects (rename, tree position) to the
 * collectives whose resolved Todos page name changed.
 */
class SettingsController extends Controller
{
	private SettingsService $settings;
	private SettingsApplierService $applier;
	private PanelDataService $panelData;
	private CollectiveMapper $collectiveMapper;
	private IURLGenerator $urlGenerator;

	public function __construct(
		string $appName,
		IRequest $request,
		SettingsService $settings,
		SettingsApplierService $applier,
		PanelDataService $panelData,
		CollectiveMapper $collectiveMapper,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request);
		$this->settings = $settings;
		$this->applier = $applier;
		$this->panelData = $panelData;
		$this->collectiveMapper = $collectiveMapper;
		$this->urlGenerator = $urlGenerator;
	}

	#[AuthorizedAdminSetting(settings: \OCA\CollectiveTodos\Settings\Admin::class)]
	public function save(): Response
	{
		$defaults = [
			SettingsService::KEY_TODOS_PAGE_NAME => (string)$this->request->getParam('default_todos_page_name', ''),
			SettingsService::KEY_TODOS_PAGE_EMOJI => (string)$this->request->getParam('default_todos_page_emoji', ''),
			SettingsService::KEY_TREE_POSITION => (string)$this->request->getParam('default_tree_position', ''),
			SettingsService::KEY_MAX_CHECKBOXES => (string)$this->request->getParam('default_max_checkboxes', ''),
		];
		/** @var array<int, array<string, string>> $override */
		$override = $this->request->getParam('override', []);
		$errors = [];

		// Validate everything first: on invalid input nothing is stored
		$defaultOperations = [];
		foreach ($defaults as $key => $value) {
			try {
				$defaultOperations[] = [$key, $this->normalize($key, $value)];
			} catch (\InvalidArgumentException $e) {
				$errors[] = $e->getMessage();
			}
		}

		$overrideOperations = [];
		foreach ($override as $collectiveId => $values) {
			foreach ($defaults as $key => $unused) {
				$value = (string)($values[$key] ?? '');
				if ($value === '') {
					continue;
				}
				try {
					$overrideOperations[] = [(int)$collectiveId, $key, $this->normalize($key, $value)];
				} catch (\InvalidArgumentException $e) {
					$errors[] = 'Collective ' . $collectiveId . ': ' . $e->getMessage();
				}
			}
		}

		$collectiveIds = [];
		foreach ($this->collectiveMapper->getAll() as $collective) {
			/** @var Collective $collective */
			if ($collective->getTrashTimestamp() === null) {
				$collectiveIds[] = (int)$collective->getId();
			}
		}

		$submitted = [
			'default_todos_page_name' => $defaults[SettingsService::KEY_TODOS_PAGE_NAME],
			'default_todos_page_emoji' => $defaults[SettingsService::KEY_TODOS_PAGE_EMOJI],
			'default_tree_position' => $defaults[SettingsService::KEY_TREE_POSITION],
			'default_max_checkboxes' => $defaults[SettingsService::KEY_MAX_CHECKBOXES],
			'override' => $override,
		];

		if ($errors !== []) {
			return new TemplateResponse('collectives_todos', 'admin', $this->panelData->getPanelData($submitted, $errors));
		}

		// Snapshot before any write: which collectives are affected
		$oldFilenames = [];
		$oldPositions = [];
		$oldEmojis = [];
		foreach ($collectiveIds as $collectiveId) {
			$oldFilenames[$collectiveId] = $this->settings->resolveTodosPageFilename($collectiveId);
			$oldPositions[$collectiveId] = $this->settings->resolve($collectiveId, SettingsService::KEY_TREE_POSITION);
			$oldEmojis[$collectiveId] = $this->settings->resolve($collectiveId, SettingsService::KEY_TODOS_PAGE_EMOJI);
		}

		foreach ($defaultOperations as [$key, $value]) {
			$this->settings->setDefault($key, $value);
		}
		foreach ($overrideOperations as [$collectiveId, $key, $value]) {
			$this->settings->setOverride($collectiveId, $key, $value);
		}
		// Override keys emptied by the form are cleared
		foreach ($override as $collectiveId => $values) {
			foreach ($defaults as $key => $unused) {
				if ((string)($values[$key] ?? '') === '') {
					$this->settings->clearOverride((int)$collectiveId, $key);
				}
			}
		}

		$applyErrors = [];
		foreach ($collectiveIds as $collectiveId) {
			$changed = $this->settings->resolveTodosPageFilename($collectiveId) !== $oldFilenames[$collectiveId]
				|| $this->settings->resolve($collectiveId, SettingsService::KEY_TREE_POSITION) !== $oldPositions[$collectiveId]
				|| $this->settings->resolve($collectiveId, SettingsService::KEY_TODOS_PAGE_EMOJI) !== $oldEmojis[$collectiveId];
			if ($changed) {
				$error = $this->applier->applyToCollective($collectiveId, $oldFilenames[$collectiveId]);
				if ($error !== null) {
					$applyErrors[] = 'Collective ' . $collectiveId . ': ' . $error;
				}
			}
		}

		if ($applyErrors !== []) {
			return new TemplateResponse('collectives_todos', 'admin', $this->panelData->getPanelData([], $applyErrors));
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.adminsettings.index', ['section' => 'collectives_todos'])
		);
	}

	private function normalize(string $key, string $value): string
	{
		return match ($key) {
			SettingsService::KEY_TODOS_PAGE_NAME => SettingsService::normalizePageName($value),
			SettingsService::KEY_TODOS_PAGE_EMOJI => SettingsService::normalizePageEmoji($value),
			SettingsService::KEY_TREE_POSITION => SettingsService::normalizePosition($value),
			SettingsService::KEY_MAX_CHECKBOXES => (string)SettingsService::normalizeMaxCheckboxes($value),
			default => throw new \InvalidArgumentException('Unknown config key: ' . $key),
		};
	}
}
