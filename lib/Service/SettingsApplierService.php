<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Apply settings side effects to a single collective after its settings
 * changed: rename the Todos page file to the newly configured name and
 * re-assert the tree position. Also implements enabling and disabling the
 * Todos page management for a collective.
 *
 * Failures are reported as error messages (never thrown), so one failing
 * collective does not abort applying settings to the others.
 */
class SettingsApplierService
{
	private CheckboxAggregator $aggregator;
	private TodosPageGenerator $todosPageGenerator;
	private SettingsService $settings;
	private PageOrderingService $ordering;
	private PageEmojiService $emojiService;
	private LoggerInterface $logger;

	public function __construct(
		CheckboxAggregator $aggregator,
		TodosPageGenerator $todosPageGenerator,
		SettingsService $settings,
		PageOrderingService $ordering,
		PageEmojiService $emojiService,
		LoggerInterface $logger
	) {
		$this->aggregator = $aggregator;
		$this->todosPageGenerator = $todosPageGenerator;
		$this->settings = $settings;
		$this->ordering = $ordering;
		$this->emojiService = $emojiService;
		$this->logger = $logger;
	}

	/**
	 * @param int $collectiveId The collective the settings changed for
	 * @param string $oldFilename The Todos page filename resolved before the change
	 * @return string|null Error message, or null on success
	 */
	public function applyToCollective(int $collectiveId, string $oldFilename): ?string
	{
		if (!$this->settings->isEnabled($collectiveId)) {
			return null;
		}

		try {
			$collectiveFolder = $this->aggregator->getFolder((string)$collectiveId);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not resolve collective ' . $collectiveId . ' for settings apply: ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not resolve the folder of collective ' . $collectiveId . ': ' . $e->getMessage();
		}

		$newFilename = $this->settings->resolveTodosPageFilename($collectiveId);
		$error = null;

		if ($oldFilename !== $newFilename && $collectiveFolder->nodeExists($oldFilename)) {
			if ($collectiveFolder->nodeExists($newFilename)) {
				$error = 'A page named ' . $newFilename . ' already exists in this collective, the Todos page was not renamed.';
				$this->logger->warning('collectives_todos: ' . $error);
			} else {
				try {
					$todosFile = $collectiveFolder->get($oldFilename);
					$todosFile->move($collectiveFolder->getPath() . '/' . $newFilename);
				} catch (\Throwable $e) {
					$this->logger->warning('collectives_todos: could not rename Todos page of collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
					return 'Could not rename the Todos page to ' . $newFilename . ': ' . $e->getMessage();
				}
			}
		}

		try {
			$this->ordering->enforcePosition($collectiveFolder, $collectiveId);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not enforce tree position for collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not enforce the Todos page position: ' . $e->getMessage();
		}

		try {
			$this->emojiService->enforceEmoji($collectiveFolder, $collectiveId);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not enforce Todos page emoji for collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not enforce the Todos page emoji: ' . $e->getMessage();
		}

		return $error;
	}

	/**
	 * Enable the Todos page management for a collective and generate the
	 * Todos page right away. The checkbox cache kept being maintained while
	 * the collective was disabled, so the page is consistent immediately.
	 *
	 * @return string|null Error message, or null on success
	 */
	public function enableCollective(int $collectiveId): ?string
	{
		try {
			$this->settings->setOverride($collectiveId, SettingsService::KEY_ENABLED, SettingsService::VALUE_ENABLED);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not enable collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not enable the Todos page: ' . $e->getMessage();
		}
		return $this->enableTodosPage($collectiveId);
	}

	/**
	 * Disable the Todos page management for a collective and delete its
	 * Todos page (Collectives moves it to trash). The disabled flag is
	 * written first so the delete event's listeners already see the
	 * collective as disabled and do not recreate the page.
	 *
	 * @return string|null Error message, or null on success
	 */
	public function disableCollective(int $collectiveId): ?string
	{
		try {
			$this->settings->setOverride($collectiveId, SettingsService::KEY_ENABLED, SettingsService::VALUE_DISABLED);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not disable collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not disable the Todos page: ' . $e->getMessage();
		}
		return $this->disableTodosPage($collectiveId);
	}

	/**
	 * Side effects of a collective becoming enabled: generate its Todos
	 * page. The enabled config must already be written.
	 *
	 * @return string|null Error message, or null on success
	 */
	public function enableTodosPage(int $collectiveId): ?string
	{
		try {
			$collectiveFolder = $this->aggregator->getFolder((string)$collectiveId);
			$this->todosPageGenerator->regenerateTodosPage($collectiveFolder);
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not generate the Todos page of collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not generate the Todos page: ' . $e->getMessage();
		}
		return null;
	}

	/**
	 * Side effects of a collective becoming disabled: delete its Todos
	 * page (Collectives moves it to trash). The disabled config must
	 * already be written, so the delete event's listeners do not recreate
	 * the page.
	 *
	 * @return string|null Error message, or null on success
	 */
	public function disableTodosPage(int $collectiveId): ?string
	{
		try {
			$collectiveFolder = $this->aggregator->getFolder((string)$collectiveId);
			$todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);
			if ($collectiveFolder->nodeExists($todosFilename)) {
				$collectiveFolder->get($todosFilename)->delete();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('collectives_todos: could not delete the Todos page of collective ' . $collectiveId . ': ' . $e->getMessage(), ['exception' => $e]);
			return 'Could not delete the Todos page: ' . $e->getMessage();
		}
		return null;
	}
}
