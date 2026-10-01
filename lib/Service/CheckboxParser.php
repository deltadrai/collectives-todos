<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

class CheckboxParser
{
    /**
     * Parse markdown content and extract checkboxes.
     *
     * @param string $content Markdown content
     * @return array<array{text: string, checked: bool, line: int, raw: string}>
     */
    public function parse(string $content): array
    {
        $checkboxes = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $lineNumber++; // Convert from 0-based to 1-based

            // Match various list markers: -, *, +, or numbered lists
            if (preg_match('/^(\s*)([\-*+]|\d+\.)\s*\[(x|X| |)\](\s+)(.+)$/u', $line, $matches)) {
                $checked = $matches[3] !== ' ';
                $text = $matches[5];

                $checkboxes[] = [
                    'text' => $text,
                    'checked' => $checked,
                    'line' => $lineNumber,
                    'raw' => $line,
                ];
            }
        }

        return $checkboxes;
    }
}
