<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslatableStringScanner
{
    /**
     * WordPress translation functions to scan for.
     */
    private const TRANSLATION_FUNCTIONS = [
        '__',
        '_e',
        '_x',
        '_ex',
        '_n',
        '_nx',
        '_n_noop',
        '_nx_noop',
        'esc_html__',
        'esc_html_e',
        'esc_html_x',
        'esc_attr__',
        'esc_attr_e',
        'esc_attr_x',
        'translate',
        'translate_nooped_plural',
    ];

    /**
     * Scan a plugin directory for translatable strings.
     *
     * @param string $pluginPath
     * @param string|null $textDomain
     * @return int
     */
    public function countTranslatableStrings(string $pluginPath, ?string $textDomain = null): int
    {
        if (!is_dir($pluginPath)) {
            return 0;
        }

        $phpFiles = $this->findPhpFiles($pluginPath);
        $strings = [];

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Find all translation function calls
            foreach (self::TRANSLATION_FUNCTIONS as $function) {
                // Match function calls with this pattern
                $pattern = '/' . preg_quote($function, '/') . '\s*\(/i';

                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $startPos = $match[1] + strlen($match[0]);
                        $params = $this->extractBalancedParentheses($content, $startPos);

                        if ($params !== null) {
                            $parsedParams = $this->splitParameters($params);

                            // Check if this matches the text domain (if specified)
                            if ($textDomain !== null && !$this->matchesTextDomain($parsedParams, $textDomain, $function)) {
                                continue;
                            }

                            // Extract the translatable string
                            $string = $this->extractString($parsedParams, $function);
                            if ($string !== null) {
                                $strings[$string] = true; // Use array key to deduplicate
                            }
                        }
                    }
                }
            }
        }

        return count($strings);
    }

    /**
     * Extract content between balanced parentheses.
     *
     * @param string $content
     * @param int $startPos Position after the opening parenthesis
     * @return string|null
     */
    private function extractBalancedParentheses(string $content, int $startPos): ?string
    {
        $depth = 1;
        $length = strlen($content);
        $result = '';

        for ($i = $startPos; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $result;
                }
            }

            $result .= $char;
        }

        return null;
    }

    /**
     * Split function parameters by comma, respecting nested parentheses and strings.
     *
     * @param string $params
     * @return array<string>
     */
    private function splitParameters(string $params): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($params);

        for ($i = 0; $i < $length; $i++) {
            $char = $params[$i];
            $prevChar = $i > 0 ? $params[$i - 1] : '';

            // Handle string boundaries
            if (($char === '"' || $char === "'") && $prevChar !== '\\') {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }

            // Track parenthesis depth
            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $result[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $result[] = trim($current);
        }

        return $result;
    }

    /**
     * Check if parameters match the specified text domain.
     *
     * @param array<string> $params
     * @param string $textDomain
     * @param string $function
     * @return bool
     */
    private function matchesTextDomain(array $params, string $textDomain, string $function): bool
    {
        // Determine which parameter is the text domain based on the function
        $domainIndex = match ($function) {
            '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' => 1,
            '_x', '_ex', 'esc_html_x', 'esc_attr_x' => 2,
            '_n', '_nx' => 3,
            default => null,
        };

        if ($domainIndex === null || !isset($params[$domainIndex])) {
            // No text domain check possible, assume match
            return true;
        }

        $domainParam = trim($params[$domainIndex], " \t\n\r\0\x0B\"'");
        return $domainParam === $textDomain;
    }

    /**
     * Extract the translatable string from parameters.
     *
     * @param array<string> $params
     * @param string $function
     * @return string|null
     */
    private function extractString(array $params, string $function): ?string
    {
        if (empty($params)) {
            return null;
        }

        // First parameter is always the string for these functions
        $stringParam = $params[0];

        // Extract string literal
        if (preg_match('/^["\'](.+)["\']$/s', trim($stringParam), $match)) {
            return $match[1];
        }

        // For _n and _nx, also check the plural form (second parameter)
        if (in_array($function, ['_n', '_nx']) && isset($params[1])) {
            if (preg_match('/^["\'](.+)["\']$/s', trim($params[1]), $match)) {
                return $match[1]; // Return plural form as representative
            }
        }

        return null;
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
                $phpFiles[] = $file->getPathname();
            }
        }

        return $phpFiles;
    }
}
