<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslationBestPractices
{
    /**
     * Check translation function usage for best practices.
     *
     * @param string $pluginPath
     * @return array{
     *   wrong_function_usage: array<array{file: string, line: int, issue: string, context: string}>,
     *   missing_context: array<array{file: string, line: int, string: string}>,
     *   wrong_placeholder: array<array{file: string, line: int, issue: string, context: string}>,
     *   missing_translator_comments: array<array{file: string, line: int, string: string}>,
     *   total_issues: int
     * }
     */
    public function check(string $pluginPath): array
    {
        $phpFiles = $this->findPhpFiles($pluginPath);
        $wrongFunctionUsage = [];
        $missingContext = [];
        $wrongPlaceholder = [];
        $missingTranslatorComments = [];

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace($pluginPath . '/', '', $phpFile);

            // Check for _e() used when return value is needed
            $this->checkWrongEchoUsage($content, $relativePath, $wrongFunctionUsage);

            // Check for __() used when echo is intended
            $this->checkWrongReturnUsage($content, $relativePath, $wrongFunctionUsage);

            // Check for ambiguous strings without context
            $this->checkMissingContext($content, $relativePath, $missingContext);

            // Check for wrong placeholder types in sprintf
            $this->checkSprintfPlaceholders($content, $relativePath, $wrongPlaceholder);

            // Check for translator comments
            $this->checkTranslatorComments($content, $relativePath, $missingTranslatorComments);
        }

        return [
            'wrong_function_usage' => $wrongFunctionUsage,
            'missing_context' => $missingContext,
            'wrong_placeholder' => $wrongPlaceholder,
            'missing_translator_comments' => $missingTranslatorComments,
            'total_issues' => count($wrongFunctionUsage) + count($missingContext) +
                            count($wrongPlaceholder) + count($missingTranslatorComments),
        ];
    }

    /**
     * Check for _e() used where return value is needed.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<array> &$issues
     */
    private function checkWrongEchoUsage(string $content, string $relativePath, array &$issues): void
    {
        // Pattern: $var = _e(...) or return _e(...)
        $patterns = [
            '/(\$\w+\s*=\s*)_e\s*\(/' => 'Using _e() in assignment (should use __()',
            '/return\s+_e\s*\(/'      => 'Using _e() in return statement (should use __()',
        ];

        foreach ($patterns as $pattern => $issue) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $context = $this->getContext($content, $offset);

                    $issues[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'issue' => $issue,
                        'context' => trim($context),
                    ];
                }
            }
        }
    }

    /**
     * Check for __() used where echo is likely intended.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<array> &$issues
     */
    private function checkWrongReturnUsage(string $content, string $relativePath, array &$issues): void
    {
        // Pattern: echo __(...) without esc_html or esc_attr
        if (preg_match_all('/echo\s+__\s*\([^\)]+\)[^;]*;/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $fullMatch = $match[0];
                $offset = $match[1];

                // Check if it's wrapped in esc_html or esc_attr
                if (preg_match('/esc_(?:html|attr)/', $fullMatch)) {
                    continue; // This is OK
                }

                $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                $context = $this->getContext($content, $offset);

                $issues[] = [
                    'file' => $relativePath,
                    'line' => $lineNumber,
                    'issue' => 'Using echo __() without escaping (should use esc_html_e() or esc_html__())',
                    'context' => trim($context),
                ];
            }
        }
    }

    /**
     * Check for ambiguous strings that should use _x() for context.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<array> &$issues
     */
    private function checkMissingContext(string $content, string $relativePath, array &$issues): void
    {
        // Common ambiguous words that should have context
        $ambiguousWords = [
            'Read', 'Close', 'Save', 'Post', 'Comment', 'Date', 'Time',
            'View', 'Edit', 'Delete', 'Name', 'Title', 'Author',
        ];

        foreach ($ambiguousWords as $word) {
            // Find __('Word', 'domain') without context
            $pattern = '/__\s*\(\s*[\'"]' . preg_quote($word, '/') . '[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*\)/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                    $issues[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'string' => $word,
                    ];
                }
            }
        }
    }

    /**
     * Check for wrong sprintf placeholder usage.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<array> &$issues
     */
    private function checkSprintfPlaceholders(string $content, string $relativePath, array &$issues): void
    {
        // Find sprintf with translation functions
        if (preg_match_all('/sprintf\s*\(\s*(?:__|_e|_x|_n|_nx|esc_html__|esc_attr__)\s*\([^\)]+\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $fullMatch = $match[0];
                $offset = $match[1];

                // Check for %d used with non-numeric context
                if (preg_match('/%d.*(?:name|title|label|text)/i', $fullMatch)) {
                    $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $context = $this->getContext($content, $offset);

                    $issues[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'issue' => 'Using %d placeholder for likely non-numeric value (should use %s)',
                        'context' => trim($context),
                    ];
                }

                // Check for incorrect placeholder order
                if (preg_match_all('/%(\d+)\$/', $fullMatch, $orderMatches)) {
                    $orders = array_map('intval', $orderMatches[1]);
                    sort($orders);
                    $expected = range(1, count($orders));

                    if ($orders !== $expected) {
                        $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                        $context = $this->getContext($content, $offset);

                        $issues[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'issue' => 'Incorrect sprintf placeholder order',
                            'context' => trim($context),
                        ];
                    }
                }
            }
        }
    }

    /**
     * Check for translator comments.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<array> &$issues
     */
    private function checkTranslatorComments(string $content, string $relativePath, array &$issues): void
    {
        // Find translation calls with placeholders that should have translator comments
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Check if line has translation with placeholder
            if (preg_match('/__\s*\([^)]*%[sd]/', $line)) {
                // Check if previous line has translator comment
                $prevLine = $lineNum > 0 ? $lines[$lineNum - 1] : '';

                if (!preg_match('/\/\/\s*translators:/i', $prevLine) &&
                    !preg_match('/\/\*\s*translators:/i', $prevLine)) {

                    // Extract the string for reference
                    if (preg_match('/__\s*\(\s*[\'"]([^\'")]+)[\'"]/', $line, $stringMatch)) {
                        $issues[] = [
                            'file' => $relativePath,
                            'line' => $lineNum + 1,
                            'string' => $stringMatch[1],
                        ];
                    }
                }
            }
        }
    }

    /**
     * Get context around a position.
     *
     * @param string $content
     * @param int $offset
     * @return string
     */
    private function getContext(string $content, int $offset): string
    {
        $start = max(0, $offset - 50);
        $length = 150;
        return substr($content, $start, $length);
    }

    /**
     * Find all PHP files in a plugin directory.
     *
     * @param string $pluginPath
     * @return array<string>
     */
    private function findPhpFiles(string $pluginPath): array
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\UnexpectedValueException) {
            return [];
        }

        $phpFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                if (strpos($path, '/vendor/') !== false ||
                    strpos($path, '/node_modules/') !== false ||
                    strpos($path, '/languages/') !== false) {
                    continue;
                }
                $phpFiles[] = $path;
            }
        }

        return $phpFiles;
    }
}
