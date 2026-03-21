<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class JavaScriptI18nScanner
{
    /**
     * WordPress i18n functions in JavaScript.
     */
    private const JS_I18N_FUNCTIONS = [
        '__',
        '_x',
        '_n',
        '_nx',
        'sprintf',
        'isRTL',
    ];

    /**
     * Scan JavaScript files for i18n usage.
     *
     * @param string $pluginPath
     * @param string|null $expectedTextDomain
     * @return array{
     *   has_js_files: bool,
     *   has_translations: bool,
     *   script_registrations: array<string>,
     *   translated_strings: array<string>,
     *   untranslated_strings: array<array{string: string, file: string, line: int}>,
     *   text_domain_issues: array<array{file: string, line: int, actual: string, expected: string}>,
     *   total_js_strings: int,
     *   total_translated: int
     * }
     */
    public function scan(string $pluginPath, ?string $expectedTextDomain = null): array
    {
        $jsFiles = $this->findJavaScriptFiles($pluginPath);
        $scriptRegistrations = $this->findScriptRegistrations($pluginPath);
        $translatedStrings = [];
        $untranslatedStrings = [];
        $textDomainIssues = [];

        foreach ($jsFiles as $jsFile) {
            $content = @file_get_contents($jsFile);
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace($pluginPath . '/', '', $jsFile);

            // Find translated strings
            $translated = $this->findTranslatedStrings($content, $relativePath, $expectedTextDomain, $textDomainIssues);
            $translatedStrings = array_merge($translatedStrings, $translated);

            // Find untranslated strings
            $untranslated = $this->findUntranslatedStrings($content, $relativePath, $translated);
            $untranslatedStrings = array_merge($untranslatedStrings, $untranslated);
        }

        return [
            'has_js_files' => !empty($jsFiles),
            'has_translations' => !empty($translatedStrings),
            'script_registrations' => $scriptRegistrations,
            'translated_strings' => array_unique($translatedStrings),
            'untranslated_strings' => $untranslatedStrings,
            'text_domain_issues' => $textDomainIssues,
            'total_js_strings' => count($translatedStrings) + count($untranslatedStrings),
            'total_translated' => count($translatedStrings),
        ];
    }

    /**
     * Find translated strings using wp.i18n functions.
     *
     * @param string $content
     * @param string $relativePath
     * @param string|null $expectedTextDomain
     * @param array<array> &$textDomainIssues
     * @return array<string>
     */
    private function findTranslatedStrings(
        string $content,
        string $relativePath,
        ?string $expectedTextDomain,
        array &$textDomainIssues
    ): array {
        $strings = [];

        // Match wp.i18n.__ or __ (if destructured)
        foreach (self::JS_I18N_FUNCTIONS as $function) {
            // Match both wp.i18n.__() and __() forms
            $patterns = [
                '/wp\.i18n\.' . preg_quote($function, '/') . '\s*\(/',
                '/(?<![a-zA-Z0-9_])' . preg_quote($function, '/') . '\s*\(/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $startPos = $match[1] + strlen($match[0]);
                        $params = $this->extractFunctionParams($content, $startPos);

                        if (!empty($params)) {
                            // First parameter is the string
                            $string = $this->cleanJsString($params[0]);
                            if ($string !== null && strlen($string) > 0) {
                                $strings[] = $string;
                            }

                            // Check text domain (second parameter for __ and _x, third for _n and _nx)
                            $domainIndex = in_array($function, ['_n', '_nx']) ? 2 : 1;
                            if (isset($params[$domainIndex]) && $expectedTextDomain !== null) {
                                $domain = $this->cleanJsString($params[$domainIndex]);
                                if ($domain !== null && $domain !== $expectedTextDomain) {
                                    $lineNumber = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                                    $textDomainIssues[] = [
                                        'file' => $relativePath,
                                        'line' => $lineNumber,
                                        'actual' => $domain,
                                        'expected' => $expectedTextDomain,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_unique($strings);
    }

    /**
     * Find untranslated user-facing strings in JavaScript.
     *
     * @param string $content
     * @param string $relativePath
     * @param array<string> $translatedStrings
     * @return array<array{string: string, file: string, line: int}>
     */
    private function findUntranslatedStrings(string $content, string $relativePath, array $translatedStrings): array
    {
        $untranslated = [];
        $translatedSet = array_flip($translatedStrings);

        // Find all string literals (single and double quotes)
        // This is a simplified pattern - real JS parsing would be more complex
        preg_match_all('/(["\'])(?:(?=(\\\\?))\2.)*?\1/', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $fullString = $match[0];
            $offset = $match[1];

            // Remove quotes
            $string = substr($fullString, 1, -1);

            // Skip if too short or already translated
            if (strlen($string) < 10 || isset($translatedSet[$string])) {
                continue;
            }

            // Skip technical strings
            if ($this->isTechnicalJsString($string, $content, $offset)) {
                continue;
            }

            // Check if it looks like user-facing text
            if (!$this->isUserFacingJsString($string)) {
                continue;
            }

            $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

            $untranslated[] = [
                'string' => $string,
                'file' => $relativePath,
                'line' => $lineNumber,
            ];
        }

        return $untranslated;
    }

    /**
     * Find wp_set_script_translations() calls in PHP files.
     *
     * @param string $pluginPath
     * @return array<string>
     */
    private function findScriptRegistrations(string $pluginPath): array
    {
        $registrations = [];
        $phpFiles = $this->findPhpFiles($pluginPath);

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Find wp_set_script_translations() calls
            if (preg_match_all('/wp_set_script_translations\s*\(\s*["\']([^"\']+)/', $content, $matches)) {
                foreach ($matches[1] as $scriptHandle) {
                    $registrations[] = $scriptHandle;
                }
            }
        }

        return array_unique($registrations);
    }

    /**
     * Check if a JavaScript string is technical/non-user-facing.
     *
     * @param string $string
     * @param string $content
     * @param int $offset
     * @return bool
     */
    private function isTechnicalJsString(string $string, string $content, int $offset): bool
    {
        // Check technical patterns
        $technicalPatterns = [
            '/^[a-z_][a-z0-9_-]*$/i',        // Identifiers: className, data-attribute
            '/^https?:\/\//i',                // URLs
            '/^\//',                          // Paths
            '/^#[0-9a-f]{3,6}$/i',           // Color codes
            '/^\d+(\.\d+)?[a-z%]*$/i',       // Numbers with units
            '/^SELECT|INSERT|UPDATE/i',       // SQL
            '/^[\w-]+\.(js|css|json|html)$/', // Filenames
            '/^[A-Z_]+$/',                    // Constants
            '/^data-/',                       // Data attributes
            '/^aria-/',                       // ARIA attributes
            '/^wp-/',                         // WordPress prefixes
        ];

        foreach ($technicalPatterns as $pattern) {
            if (preg_match($pattern, $string)) {
                return true;
            }
        }

        // Check context - is it a property key?
        $context = substr($content, max(0, $offset - 20), 40);
        if (preg_match('/[{,]\s*["\']' . preg_quote($string, '/') . '["\']:\s*/', $context)) {
            return true; // Object property key
        }

        return false;
    }

    /**
     * Check if a string is likely user-facing.
     *
     * @param string $string
     * @return bool
     */
    private function isUserFacingJsString(string $string): bool
    {
        // Must contain at least one space (be a phrase)
        if (!preg_match('/\s/', $string)) {
            return false;
        }

        // Must have sufficient letters
        $alphaCount = preg_match_all('/[a-zA-Z]/', $string);
        if ($alphaCount < 5) {
            return false;
        }

        // Common user-facing patterns
        $userFacingPatterns = [
            '/^[A-Z]/',                      // Starts with capital letter
            '/\w+\s+\w+/',                   // Multiple words
            '/[.!?]$/',                      // Ends with punctuation
        ];

        foreach ($userFacingPatterns as $pattern) {
            if (preg_match($pattern, $string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract function parameters from JavaScript.
     *
     * @param string $content
     * @param int $startPos
     * @return array<string>
     */
    private function extractFunctionParams(string $content, int $startPos): array
    {
        $params = [];
        $current = '';
        $depth = 1;
        $inString = false;
        $stringChar = null;
        $length = strlen($content);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $content[$i];
            $prevChar = $i > 0 ? $content[$i - 1] : '';

            // Handle string boundaries
            if (($char === '"' || $char === "'" || $char === '`') && $prevChar !== '\\') {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }

            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        if (trim($current) !== '') {
                            $params[] = trim($current);
                        }
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $params[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        return $params;
    }

    /**
     * Clean a JavaScript string literal.
     *
     * @param string $string
     * @return string|null
     */
    private function cleanJsString(string $string): ?string
    {
        $string = trim($string);

        // Remove quotes
        if (preg_match('/^(["\'])(.*)\1$/', $string, $match)) {
            return $match[2];
        }

        // Remove backticks (template literals)
        if (preg_match('/^`(.*)`$/', $string, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Find all JavaScript files in a plugin directory.
     *
     * @param string $pluginPath
     * @return array<string>
     */
    private function findJavaScriptFiles(string $pluginPath): array
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

        $jsFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $path = $file->getPathname();
                // Skip minified files, vendor, and node_modules
                if (strpos($path, '.min.js') !== false ||
                    strpos($path, '/vendor/') !== false ||
                    strpos($path, '/node_modules/') !== false) {
                    continue;
                }
                $jsFiles[] = $path;
            }
        }

        return $jsFiles;
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
                    strpos($path, '/node_modules/') !== false) {
                    continue;
                }
                $phpFiles[] = $path;
            }
        }

        return $phpFiles;
    }
}
