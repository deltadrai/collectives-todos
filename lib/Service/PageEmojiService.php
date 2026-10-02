<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCA\Collectives\Db\PageMapper;
use OCP\Files\Folder;

/**
 * Enforce the configured emoji of the Todos page: the Collectives app
 * stores a page's emoji in the `collectives_pages` row and renders it in
 * the page tree. An empty configured value clears the emoji (stored as
 * null), like Collectives itself does.
 */
class PageEmojiService
{
	private PageMapper $pageMapper;
	private SettingsService $settings;

	public function __construct(PageMapper $pageMapper, SettingsService $settings)
	{
		$this->pageMapper = $pageMapper;
		$this->settings = $settings;
	}

	/**
	 * Write the configured Todos page emoji to the page's collectives_pages
	 * row. Silently skipped when the Todos page or its row is missing.
	 */
	public function enforceEmoji(Folder $collectiveFolder, ?int $collectiveId): void
	{
		$emoji = $this->settings->resolve($collectiveId, SettingsService::KEY_TODOS_PAGE_EMOJI);
		$todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

		try {
			$todosFile = $collectiveFolder->get($todosFilename);
		} catch (\OCP\Files\NotFoundException $e) {
			return;
		}

		$page = $this->pageMapper->findByFileId((int)$todosFile->getId());
		if ($page === null) {
			return;
		}

		$current = $page->getEmoji() ?? '';
		if ($current === $emoji) {
			return;
		}

		$page->setEmoji($emoji === '' ? null : $emoji);
		$this->pageMapper->update($page);
	}
}
