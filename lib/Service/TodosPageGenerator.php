<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\Files\Folder;

class TodosPageGenerator
{

    private CheckboxAggregator $aggregator;
    private PageLinkBuilder $linkBuilder;
    private TextDocumentResetter $textResetter;
    private SettingsService $settings;
    private PageOrderingService $ordering;
    private PageEmojiService $emojiService;

    public function __construct(
        CheckboxAggregator $aggregator,
        PageLinkBuilder $linkBuilder,
        TextDocumentResetter $textResetter,
        SettingsService $settings,
        PageOrderingService $ordering,
        PageEmojiService $emojiService
    ) {
        $this->aggregator = $aggregator;
        $this->linkBuilder = $linkBuilder;
        $this->textResetter = $textResetter;
        $this->settings = $settings;
        $this->ordering = $ordering;
        $this->emojiService = $emojiService;
    }

    /**
     * Generate and save the Todos page for a collectives.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     */
    public function regenerateTodosPage(Folder $collectiveFolder): void
    {
        $collectiveId = $this->aggregator->getCollectiveId($collectiveFolder);
        $filename = $this->settings->resolveTodosPageFilename($collectiveId);
        $content = $this->generateContent($collectiveFolder, $filename);
        $this->saveTodosPage($collectiveFolder, $content, $filename);
        $this->ordering->enforcePosition($collectiveFolder, $collectiveId);
        $this->emojiService->enforceEmoji($collectiveFolder, $collectiveId);
    }

    /**
     * Generate the markdown content for the Todos page.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     */
    public function generateContent(Folder $collectiveFolder, string $todosFilename): string
    {
        $pages = $this->aggregator->getAllCheckboxes($collectiveFolder);
        $collectiveId = $this->aggregator->getCollectiveId($collectiveFolder);
        $updated = (new \DateTime())->format('Y-m-d H:i:s T');

        $lines = [];
        $lines[] = '# ' . pathinfo($todosFilename, PATHINFO_FILENAME);
        $lines[] = '';
        $lines[] = '*Auto-generated from all pages in this collectives. Last updated: ' . $updated . '* ';
        $lines[] = '';

        $hasCheckboxes = false;

        foreach ($pages as $pageId => $pageData) {
            $checkboxes = $pageData['checkboxes'];
            if (empty($checkboxes)) {
                continue;
            }

            $hasCheckboxes = true;
            $header = $pageData['title'];
            if ($this->hasDuplicateTitles($pages, $header)) {
                $header .= ' (' . $pageId . ')';
            }
            $lines[] = '## ' . $this->formatHeader($header, $collectiveId, (string)$pageId, $pageData['title']);
            $lines[] = '';

            foreach ($checkboxes as $checkbox) {
                $checked = $checkbox['checked'] ? 'x' : ' ';
                $lines[] = '- [' . $checked . '] ' . $checkbox['text'];
            }

            if ($pageData['truncated'] ?? false) {
                $lines[] = '';
                $lines[] = '*(list truncated by the settings limit)*';
            }

            $lines[] = '';
        }

        if (!$hasCheckboxes) {
            $lines[] = 'No tasks found in this collectives.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Check if multiple pages have the same title.
     */
    private function hasDuplicateTitles(array $pages, string $title): bool
    {
        $count = 0;
        foreach ($pages as $pageData) {
            if ($pageData['title'] === $title) {
                $count++;
                if ($count >= 2) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Format a page header, linking to the page if its URL can be resolved.
     */
    private function formatHeader(string $header, ?int $collectiveId, string $pageId, string $title): string
    {
        if ($collectiveId !== null) {
            $url = $this->linkBuilder->getPageUrl($collectiveId, $pageId, $title);
            if ($url !== null) {
                return '[' . $this->escapeLinkText($header) . '](' . $url . ')';
            }
        }

        return $this->sanitizeHeader($header);
    }

    /**
     * Escape a string for use as markdown link text.
     */
    private function escapeLinkText(string $title): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $title);
    }

    /**
     * Sanitize a string for use as a markdown header.
     */
    private function sanitizeHeader(string $title): string
    {
        return str_replace(['#', '[', ']', '(', ')', '*', '_', '~'], '', $title);
    }

    /**
     * Save the Todos page content to the collectives folder and reset the
     * Text editor state of the Todos page (its content changed externally).
     *
     * If the existing page already carries the same content except for the
     * auto-generated "Last updated" timestamp, the page is left untouched:
     * rewriting it would change the file's etag behind open editor sessions
     * (error dialogs, conflict diffs), even though no todo changed.
     */
    private function saveTodosPage(Folder $collectiveFolder, string $content, string $todosFilename): void
    {
        if ($collectiveFolder->nodeExists($todosFilename)) {
            $todosFile = $collectiveFolder->get($todosFilename);
            if ($todosFile instanceof \OCP\Files\File) {
                if ($this->isUnchangedExceptTimestamp((string)$todosFile->getContent(), $content)) {
                    return;
                }
                $todosFile->putContent($content);
                $this->textResetter->resetForFile((int)$todosFile->getId());
                return;
            }
        }
        $todosFile = $collectiveFolder->newFile($todosFilename, $content);
        $this->textResetter->resetForFile((int)$todosFile->getId());
    }

    /**
     * Whether the current and the freshly generated Todos page content are
     * identical except for the "Last updated" timestamp line.
     */
    private function isUnchangedExceptTimestamp(string $current, string $generated): bool
    {
        return $this->withoutTimestamp($current) === $this->withoutTimestamp($generated);
    }

    private function withoutTimestamp(string $content): string
    {
        return (string)preg_replace(
            '/^\*Auto-generated from all pages in this collectives\. Last updated: [^\n]*$/m',
            '*Auto-generated*',
            $content
        );
    }
}
