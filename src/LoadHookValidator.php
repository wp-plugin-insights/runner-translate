<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class LoadHookValidator
{
    /**
     * Validate when load_plugin_textdomain is called.
     *
     * @param string $pluginPath
     * @return array{
     *   has_load_call: bool,
     *   hook_usage: array<array{file: string, line: int, hook: string|null, is_correct: bool}>,
     *   issues: array<string>
     * }
     */
    public function validate(string $pluginPath): array
    {
        $phpFiles = $this->findPhpFiles($pluginPath);
        $loadCalls = [];
        $issues = [];

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace($pluginPath . '/', '', $phpFile);

            // Find load_plugin_textdomain calls
            if (preg_match_all('/load_plugin_textdomain\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                    // Check the context - is it in a hook?
                    $hook = $this->findHookContext($content, $offset);
                    $isCorrect = $this->isCorrectHook($hook);

                    $loadCalls[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'hook' => $hook,
                        'is_correct' => $isCorrect,
                    ];

                    if (!$isCorrect) {
                        if ($hook === null) {
                            $issues[] = sprintf(
                                'load_plugin_textdomain() called directly in %s:%d (should be hooked to init or plugins_loaded)',
                                $relativePath,
                                $lineNumber
                            );
                        } else {
                            $issues[] = sprintf(
                                'load_plugin_textdomain() called on wrong hook "%s" in %s:%d',
                                $hook,
                                $relativePath,
                                $lineNumber
                            );
                        }
                    }
                }
            }
        }

        return [
            'has_load_call' => !empty($loadCalls),
            'hook_usage' => $loadCalls,
            'issues' => $issues,
        ];
    }

    /**
     * Find the hook context for a function call.
     *
     * @param string $content
     * @param int $offset
     * @return string|null
     */
    private function findHookContext(string $content, int $offset): ?string
    {
        // Look backwards to find if this is inside an add_action/add_filter call
        // or if it's in a function that's hooked

        // Get a larger context before the load_plugin_textdomain call
        $contextStart = max(0, $offset - 1000);
        $context = substr($content, $contextStart, $offset - $contextStart);

        // Check if we're inside a function that's being added to a hook
        // Pattern: add_action('hook_name', 'function_name' or function() { ... load_plugin_textdomain
        if (preg_match_all('/add_action\s*\(\s*[\'"]([^\'")]+)[\'"]/', $context, $matches)) {
            // Get the last add_action before our position
            return end($matches[1]);
        }

        // Check for inline closure: add_action('hook', function() { ... load_plugin_textdomain
        if (preg_match('/add_action\s*\(\s*[\'"]([^\'")]+)[\'"]\s*,\s*function\s*\(/', $context)) {
            preg_match_all('/add_action\s*\(\s*[\'"]([^\'")]+)[\'"]/', $context, $matches);
            return end($matches[1]);
        }

        return null;
    }

    /**
     * Check if the hook is correct for loading textdomain.
     *
     * @param string|null $hook
     * @return bool
     */
    private function isCorrectHook(?string $hook): bool
    {
        if ($hook === null) {
            return false; // Called directly, not hooked
        }

        // Correct hooks for loading textdomain
        $correctHooks = [
            'plugins_loaded',
            'init',
            'after_setup_theme',
        ];

        return in_array($hook, $correctHooks, true);
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
