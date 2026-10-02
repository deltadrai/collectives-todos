<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Apply settings side effects to a single collective after its settings
 * changed: rename the Todos page file to the newly configured name and
 * re-assert the tree position.
 *
 * Failures are reported as error messages (never thrown), so one failing
 * collective does not abort applying settings to the others.
 */
class SettingsApplierService
{
	private CheckboxAggregator $aggregator;
	private SettingsService $settings;
	private PageOrderingService $ordering;
	private PageEmojiService $emojiService;
	private LoggerInterface $logger;

	public function __construct(
		CheckboxAggregator $aggregator,
		SettingsService $settings,
		PageOrderingService $ordering,
		PageEmojiService $emojiService,
		LoggerInterface $logger
	) {
		$this->aggregator = $aggregator;
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
}
