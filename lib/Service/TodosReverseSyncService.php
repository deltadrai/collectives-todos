<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Apply checkbox state changes made on the Todos page back to the source
 * pages the checkboxes were aggregated from.
 *
 * Sections of the Todos page are resolved to cached pages by the page file id
 * in the heading link URL (or the heading title as fallback). Within a
 * section, checkboxes are matched to cached checkboxes by text; only
 * differing checked states produce a change. Text edits on the Todos page
 * are ignored - the next regeneration of the page discards them anyway.
 */
class TodosReverseSyncService
{
    private CheckboxParser $parser;
    private CheckboxAggregator $aggregator;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    public function __construct(
        CheckboxParser $parser,
        CheckboxAggregator $aggregator,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->parser = $parser;
        $this->aggregator = $aggregator;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    /**
     * Compare the Todos page content against the cached checkboxes and write
     * differing checked states back to the source pages.
     *
     * @param Folder $collectiveFolder The root folder of the collective
     * @param string $todosContent The current content of the Todos page
     * @return int Number of checkboxes synced to source pages
     */
    public function syncFromTodosPage(Folder $collectiveFolder, string $todosContent): int
    {
        $pages = $this->aggregator->getAllCheckboxes($collectiveFolder);
        if ($pages === []) {
            return 0;
        }

        /** @var array<string, array<int, array{line: int, checked: bool, text: string}>> $changesByPage */
        $changesByPage = [];
        /** @var array<string, array<int, int>> $usedCacheIndices */
        $usedCacheIndices = [];

        foreach ($this->parseSections($todosContent) as $section) {
            $pageId = $this->resolveSectionPageId($pages, $section['heading']);
            if ($pageId === null || !isset($pages[$pageId]['checkboxes'])) {
                continue;
            }

            foreach ($section['checkboxes'] as $todoCheckbox) {
                $change = $this->findCacheMatch(
                    $pages[$pageId]['checkboxes'],
                    $todoCheckbox,
                    $usedCacheIndices[$pageId] ?? []
                );
                if ($change === null) {
                    continue;
                }
                $usedCacheIndices[$pageId][] = $change['index'];
                $changesByPage[$pageId][] = [
                    'line' => $change['line'],
                    'checked' => $todoCheckbox['checked'],
                    'text' => $todoCheckbox['text'],
                ];
            }
        }

        $applied = 0;
        foreach ($changesByPage as $pageId => $changes) {
            $applied += $this->applyChanges((string)$pageId, $changes);
        }
        return $applied;
    }

    /**
     * @return array<int, array{heading: string, checkboxes: array<int, array{text: string, checked: bool}>}>
     */
    private function parseSections(string $todosContent): array
    {
        $sections = [];
        foreach (explode("\n", $todosContent) as $line) {
            if (preg_match('/^## (.+)$/', $line, $matches)) {
                $sections[] = ['heading' => $matches[1], 'checkboxes' => []];
                continue;
            }
            if ($sections === []) {
                continue;
            }
            foreach ($this->parser->parse($line) as $checkbox) {
                $sections[count($sections) - 1]['checkboxes'][] = [
                    'text' => $checkbox['text'],
                    'checked' => $checkbox['checked'],
                ];
            }
        }
        return $sections;
    }

    /**
     * Resolve a Todos page section heading to a cached page id, via the
     * page file id in the heading link URL or, as fallback, the title.
     *
     * @param array<string, array{title: string, page_id: string, checkboxes: array}> $pages
     */
    private function resolveSectionPageId(array $pages, string $heading): ?string
    {
        if (preg_match('/\]\(([^)]+)\)/', $heading, $matches)) {
            $url = rawurldecode($matches[1]);

            if (preg_match('/[?&]fileId=(\d+)/', $url, $idMatches)
                && isset($pages[$idMatches[1]])) {
                return (string)$idMatches[1];
            }
            if (preg_match('#-(\d+)$#', $url, $idMatches)
                && isset($pages[$idMatches[1]])) {
                return (string)$idMatches[1];
            }
        }

        $title = str_replace(['\\[', '\\]'], ['[', ']'], $heading);

        // Duplicate titles on the Todos page carry a " (pageId)" suffix
        if (preg_match('/^(.*) \(([^)]+)\)$/', $title, $matches) && isset($pages[$matches[2]])) {
            return (string)$matches[2];
        }

        foreach ($pages as $pageId => $pageData) {
            if ($pageData['title'] === $title) {
                return (string)$pageId;
            }
        }
        return null;
    }

    /**
     * Find the first unused cached checkbox with the same text and a
     * differing checked state.
     *
     * @param array<int, array{text: string, checked: bool, line: int, raw: string}> $cachedCheckboxes
     * @param array{text: string, checked: bool} $todoCheckbox
     * @param array<int, int> $usedIndices
     * @return array{index: int, line: int}|null
     */
    private function findCacheMatch(array $cachedCheckboxes, array $todoCheckbox, array $usedIndices): ?array
    {
        foreach ($cachedCheckboxes as $index => $cached) {
            if (in_array($index, $usedIndices, true)) {
                continue;
            }
            if ($cached['text'] === $todoCheckbox['text']
                && $cached['checked'] !== $todoCheckbox['checked']) {
                return ['index' => $index, 'line' => $cached['line']];
            }
        }
        return null;
    }

    /**
     * Write checkbox state changes into the source page file.
     *
     * @param array<int, array{line: int, checked: bool, text: string}> $changes
     * @return int Number of checkboxes actually applied
     */
    private function applyChanges(string $pageId, array $changes): int
    {
        $file = $this->findSourceFile($pageId);
        if ($file === null) {
            $this->logger->warning('collectives_todos: source page ' . $pageId . ' not found for reverse sync');
            return 0;
        }

        $lines = explode("\n", $file->getContent());
        $applied = 0;

        foreach ($changes as $change) {
            $targetIndex = $this->resolveTargetLine($lines, $change);

            if ($targetIndex === null) {
                $this->logger->warning(
                    'collectives_todos: could not locate checkbox "' . $change['text']
                    . '" in source page ' . $pageId . ', skipping reverse sync for it'
                );
                continue;
            }

            $lines[$targetIndex] = $this->setCheckboxState($lines[$targetIndex], $change['checked']);
            $applied++;
        }

        if ($applied > 0) {
            $file->putContent(implode("\n", $lines));
        }
        return $applied;
    }

    private function findSourceFile(string $pageId): ?File
    {
        foreach ($this->rootFolder->getById((int)$pageId) as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Locate the line of a checkbox in the source page: the cached line
     * number if it still holds the checkbox, or the only other line with
     * the same checkbox text if it drifted.
     *
     * @param array<int, string> $lines
     * @param array{line: int, checked: bool, text: string} $change
     */
    private function resolveTargetLine(array $lines, array $change): ?int
    {
        $cachedIndex = $change['line'] - 1;
        if (isset($lines[$cachedIndex]) && $this->lineIsCheckboxWithText($lines[$cachedIndex], $change['text'])) {
            return $cachedIndex;
        }

        $matches = [];
        foreach ($lines as $index => $line) {
            if ($this->lineIsCheckboxWithText($line, $change['text'])) {
                $matches[] = $index;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        return null;
    }

    private function lineIsCheckboxWithText(string $line, string $text): bool
    {
        $checkboxes = $this->parser->parse($line);
        return $checkboxes !== [] && $checkboxes[0]['text'] === $text;
    }

    private function setCheckboxState(string $line, bool $checked): string
    {
        return (string)preg_replace(
            '/\[( |x|X)\]/',
            $checked ? '[x]' : '[ ]',
            $line,
            1
        );
    }
}
