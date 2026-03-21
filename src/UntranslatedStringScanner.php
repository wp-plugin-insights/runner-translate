<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class UntranslatedStringScanner
{
    /**
     * Patterns that indicate a string is likely user-facing.
     */
    private const USER_FACING_CONTEXTS = [
        '/wp_die\s*\(\s*["\']/',             // wp_die("message")
        '/_doing_it_wrong\s*\([^,]+,\s*["\']/', // _doing_it_wrong($function, "message")
        '/trigger_error\s*\(\s*["\']/',      // trigger_error("message")
        '/add_settings_error\s*\([^,]+,[^,]+,\s*["\']/', // add_settings_error($setting, $code, "message")
        '/WP_Error\s*\([^,]+,\s*["\']/',     // new WP_Error($code, "message")
    ];

    /**
     * Patterns that indicate a string is NOT user-facing.
     */
    private const TECHNICAL_PATTERNS = [
        '/^[a-z_][a-z0-9_]*$/i',             // Variable/key names: user_id, post_type
        '/^\//',                             // File paths: /path/to/file
        '/^https?:\/\//i',                   // URLs
        '/^SELECT|INSERT|UPDATE|DELETE/i',   // SQL queries
        '/^\w+\:\:/',                        // Class references: ClassName::
        '/^[A-Z_]+$/',                       // Constants: MAX_SIZE
        '/^#[0-9a-f]{3,6}$/i',              // Color codes: #fff
        '/^\d+(\.\d+)?[a-z%]*$/i',          // Numbers with units: 100px, 50%
        '/^[\w-]+\.(php|js|css|html)$/i',   // Filenames
        '/^[a-z0-9-_]+$/i',                  // Slugs/IDs (only if short)
        '/^meta_/',                          // Meta keys
        '/^_[a-z]/',                         // Internal keys
        '/^\$/',                             // Variable references
        '/^[\[\]{}()=<>!&|,;:\.\*\+\-\/\\\]+$/', // Pure operators/syntax
        '/^[a-z-]+:[^;]+;?$/i',             // CSS properties: fill:rgba(...)
        '/^rgba?\([0-9,\s\.]+\)$/i',        // CSS colors: rgba(255,255,255,0.5)
        '/^[a-z0-9][a-z0-9-]*\.[a-z]{2,}$/i', // Domain names: example.com
        '/[a-z-]+:[^;]+;\s*[a-z-]+:/i',    // Multiple CSS properties
    ];

    /**
     * Scan a plugin directory for untranslated user-facing strings.
     *
     * @param string $pluginPath
     * @param array<string> $translatedStrings Strings that are already translated
     * @return array{count: int, strings: array<array{string: string, file: string, line: int, context: string}>}
     */
    public function findUntranslatedStrings(string $pluginPath, array $translatedStrings = []): array
    {
        if (!is_dir($pluginPath)) {
            return ['count' => 0, 'strings' => []];
        }

        $phpFiles = $this->findPhpFiles($pluginPath);
        $untranslatedStrings = [];
        $translatedSet = array_flip($translatedStrings);

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Find all string literals in the file
            preg_match_all('/(["\'])(?:(?=(\\\\?))\2.)*?\1/', $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $fullString = $match[0];
                $offset = $match[1];

                // Remove quotes
                $string = substr($fullString, 1, -1);

                // Skip empty or very short strings
                if (strlen($string) < 10) {
                    continue; // User-facing messages should be at least 10 chars
                }

                // Skip if already translated
                if (isset($translatedSet[$string])) {
                    continue;
                }

                // Skip technical/non-user-facing strings
                if ($this->isTechnicalString($string)) {
                    continue;
                }

                // Must contain at least one space (be a phrase, not a single word)
                if (!preg_match('/\s/', $string)) {
                    continue;
                }

                // Skip printf/sprintf template strings with multiple placeholders
                $placeholderCount = preg_match_all('/%\d*\$?[sdifF]/', $string);
                if ($placeholderCount > 1) {
                    continue; // Templates with multiple placeholders should be handled differently
                }

                // Get context around the string
                $context = $this->getContext($content, $offset);

                // Check if it's in a user-facing context
                if (!$this->isUserFacingContext($context)) {
                    continue;
                }

                // Skip if the string is likely already in a translation function
                if ($this->isAlreadyInTranslationFunction($context)) {
                    continue;
                }

                // Get line number
                $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                $untranslatedStrings[] = [
                    'string' => $string,
                    'file' => str_replace($pluginPath . '/', '', $phpFile),
                    'line' => $lineNumber,
                    'context' => trim($context),
                ];
            }
        }

        return [
            'count' => count($untranslatedStrings),
            'strings' => $untranslatedStrings,
        ];
    }

    /**
     * Check if a string is technical/non-user-facing.
     *
     * @param string $string
     * @return bool
     */
    private function isTechnicalString(string $string): bool
    {
        // Check against technical patterns
        foreach (self::TECHNICAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $string)) {
                return true;
            }
        }

        // PHP short echo tags
        if (preg_match('/^<\?=/', $string)) {
            return true; // <?= esc_attr(...)
        }

        // Any string containing HTML tags (without actual text content)
        if (preg_match('/<[a-z]+/i', $string)) {
            // Check if there's actual text content between the tags
            $textOnly = strip_tags($string);
            $textOnly = trim(preg_replace('/[\$%]/', '', $textOnly)); // Remove variables and placeholders

            // If less than 5 characters remain after stripping HTML, it's not translatable text
            if (strlen($textOnly) < 5) {
                return true; // HTML template fragments
            }
        }

        // CSS class names (usually not translatable)
        if (preg_match('/^[a-z][a-z0-9-]*(\s+[a-z][a-z0-9-]*)*$/', $string) && strlen($string) < 50) {
            return true; // wordpress-news hide-if-no-js
        }

        // Single word technical terms
        if (!preg_match('/\s/', $string)) {
            // Single words without spaces are often technical
            if (strlen($string) < 10 && ctype_lower($string)) {
                return true; // Likely a key/slug
            }
        }

        // Error prefixes that are usually followed by dynamic content
        if (preg_match('/^(Invalid|Error|Missing|Unknown|Failed|Unable)(\s+\w+)?\s*:\s*$/i', $string)) {
            return true; // "Invalid key: ", "Error: "
        }

        // Check if it's mostly punctuation or numbers
        $alphaCount = preg_match_all('/[a-zA-Z]/', $string);
        if ($alphaCount < 5) {
            return true; // Not enough letters to be user text (raised threshold)
        }

        return false;
    }

    /**
     * Get context around a string (50 chars before and after).
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
     * Check if the context indicates a user-facing string.
     *
     * @param string $context
     * @return bool
     */
    private function isUserFacingContext(string $context): bool
    {
        // Check if it matches user-facing patterns
        foreach (self::USER_FACING_CONTEXTS as $pattern) {
            if (preg_match($pattern, $context)) {
                return true;
            }
        }

        // Check for HTML context
        if (preg_match('/<(h[1-6]|p|div|span|label|button|title|alt|placeholder)[^>]*>/', $context)) {
            return true;
        }

        // Check for common message patterns
        if (preg_match('/(error|message|notice|warning|success|alert|title|label|description)\s*[=:]\s*["\']/', $context, $matches)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the string is already inside a translation function.
     *
     * @param string $context
     * @return bool
     */
    private function isAlreadyInTranslationFunction(string $context): bool
    {
        $translationFunctions = [
            '__', '_e', '_x', '_ex', '_n', '_nx',
            'esc_html__', 'esc_html_e', 'esc_html_x',
            'esc_attr__', 'esc_attr_e', 'esc_attr_x',
            'translate',
        ];

        foreach ($translationFunctions as $function) {
            if (preg_match('/' . preg_quote($function, '/') . '\s*\(/', $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find all PHP files in a plugin directory (recursively).
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
                // Skip vendor, node_modules, and language files
                $path = $file->getPathname();
                if (strpos($path, '/vendor/') !== false ||
                    strpos($path, '/node_modules/') !== false ||
                    strpos($path, '/languages/') !== false ||
                    strpos($path, '/lang/') !== false) {
                    continue;
                }
                $phpFiles[] = $path;
            }
        }

        return $phpFiles;
    }
}
