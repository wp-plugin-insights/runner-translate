<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TextDomainValidator
{
    /**
     * Validate text domain usage in a plugin.
     *
     * @param string $pluginPath
     * @param string $pluginSlug Expected plugin slug
     * @return array{
     *   declared: string|null,
     *   expected: string,
     *   usage: array<string, int>,
     *   variable_domains: array<array{file: string, line: int, context: string}>,
     *   mismatches: array<array{file: string, line: int, expected: string, actual: string, context: string}>,
     *   consistency_score: float,
     *   is_valid: bool,
     *   issues: array<string>
     * }
     */
    public function validate(string $pluginPath, string $pluginSlug): array
    {
        $declaredDomain = $this->findDeclaredTextDomain($pluginPath);
        $expectedDomain = $pluginSlug;
        $domainUsage = [];
        $variableDomains = [];
        $mismatches = [];
        $issues = [];

        $phpFiles = $this->findPhpFiles($pluginPath);

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Find all translation function calls
            $translationFunctions = [
                '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop',
                'esc_html__', 'esc_html_e', 'esc_html_x',
                'esc_attr__', 'esc_attr_e', 'esc_attr_x',
                'translate', 'translate_nooped_plural',
            ];

            foreach ($translationFunctions as $function) {
                $pattern = '/' . preg_quote($function, '/') . '\s*\(/';

                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $startPos = $match[1] + strlen($match[0]);
                        $params = $this->extractBalancedParentheses($content, $startPos);

                        if ($params !== null) {
                            $parsedParams = $this->splitParameters($params);
                            $domainParam = $this->getTextDomainParameter($parsedParams, $function);

                            if ($domainParam !== null) {
                                $relativePath = str_replace($pluginPath . '/', '', $phpFile);
                                $lineNumber = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                                $context = $this->getContext($content, $match[1]);

                                // Check for variable domain
                                if ($this->isVariableDomain($domainParam)) {
                                    $variableDomains[] = [
                                        'file' => $relativePath,
                                        'line' => $lineNumber,
                                        'context' => trim($context),
                                    ];
                                } else {
                                    // Track domain usage
                                    $domainUsage[$domainParam] = ($domainUsage[$domainParam] ?? 0) + 1;

                                    // Check for mismatch with expected domain
                                    if ($declaredDomain && $domainParam !== $declaredDomain) {
                                        $mismatches[] = [
                                            'file' => $relativePath,
                                            'line' => $lineNumber,
                                            'expected' => $declaredDomain,
                                            'actual' => $domainParam,
                                            'context' => trim($context),
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Calculate consistency score
        $totalUsages = array_sum($domainUsage);
        $consistencyScore = 0.0;
        if ($totalUsages > 0 && $declaredDomain) {
            $correctUsages = $domainUsage[$declaredDomain] ?? 0;
            $consistencyScore = round(($correctUsages / $totalUsages) * 100, 2);
        }

        // Build issues list
        if ($declaredDomain === null) {
            $issues[] = 'No text domain declared in plugin header';
        } elseif ($declaredDomain !== $expectedDomain) {
            $issues[] = sprintf(
                'Declared text domain "%s" does not match plugin slug "%s"',
                $declaredDomain,
                $expectedDomain
            );
        }

        if (!empty($variableDomains)) {
            $issues[] = sprintf(
                'Found %d translation call%s with variable text domain',
                count($variableDomains),
                count($variableDomains) === 1 ? '' : 's'
            );
        }

        if (count($domainUsage) > 1) {
            $domains = array_keys($domainUsage);
            $issues[] = sprintf(
                'Multiple text domains used: %s',
                implode(', ', array_map(fn($d) => "'$d'", $domains))
            );
        }

        if ($consistencyScore < 100 && $consistencyScore > 0) {
            $issues[] = sprintf(
                'Only %.1f%% of translations use the declared text domain',
                $consistencyScore
            );
        }

        $isValid = empty($issues);

        return [
            'declared' => $declaredDomain,
            'expected' => $expectedDomain,
            'usage' => $domainUsage,
            'variable_domains' => $variableDomains,
            'mismatches' => $mismatches,
            'consistency_score' => $consistencyScore,
            'is_valid' => $isValid,
            'issues' => $issues,
        ];
    }

    /**
     * Find the declared text domain from plugin header.
     *
     * @param string $pluginPath
     * @return string|null
     */
    private function findDeclaredTextDomain(string $pluginPath): ?string
    {
        $phpFiles = glob($pluginPath . '/*.php');

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Look for plugin header
            if (preg_match('/^\s*\*\s*Text Domain:\s*(.+)$/im', $content, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /**
     * Get the text domain parameter from parsed function parameters.
     *
     * @param array<string> $params
     * @param string $function
     * @return string|null
     */
    private function getTextDomainParameter(array $params, string $function): ?string
    {
        // Determine which parameter is the text domain based on the function
        $domainIndex = match ($function) {
            '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' => 1,
            '_x', '_ex', 'esc_html_x', 'esc_attr_x' => 2,
            '_n', '_nx' => 3,
            '_n_noop', '_nx_noop' => 2,
            'translate' => 1,
            'translate_nooped_plural' => 1,
            default => null,
        };

        if ($domainIndex === null || !isset($params[$domainIndex])) {
            return null;
        }

        $domainParam = trim($params[$domainIndex]);

        // Extract string literal
        if (preg_match('/^["\']([^"\']+)["\']$/', $domainParam, $match)) {
            return $match[1];
        }

        // Return the raw parameter if it's not a string literal (might be a variable)
        return $domainParam;
    }

    /**
     * Check if the domain parameter is a variable (not a string literal).
     *
     * @param string $domain
     * @return bool
     */
    private function isVariableDomain(string $domain): bool
    {
        // Check if it starts with $ or contains function calls
        return preg_match('/^\$|^[a-z_][a-z0-9_]*\(/i', $domain) === 1;
    }

    /**
     * Extract content between balanced parentheses.
     *
     * @param string $content
     * @param int $startPos
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
     * Get context around a position (50 chars before and after).
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
