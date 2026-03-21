<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslationFileParser
{
    /**
     * Scan a plugin directory for translation files and extract locale data.
     *
     * @param string $pluginPath
     * @return array<string, array<string, mixed>>
     */
    public function scanPluginDirectory(string $pluginPath): array
    {
        $locales = [];

        if (!is_dir($pluginPath)) {
            return $locales;
        }

        // Find the registered translation directory from plugin code
        $registeredPath = $this->findRegisteredTranslationPath($pluginPath);

        // If no registered path is found, translations won't work in WordPress
        if ($registeredPath === null) {
            return $locales;
        }

        $potFile = null;

        // Find .pot file (template with all strings)
        $potFiles = glob($registeredPath . '/*.pot');
        if (!empty($potFiles)) {
            $potFile = $potFiles[0];
        }

        // Find all .po files (language-specific translations)
        $poFiles = glob($registeredPath . '/*.po');

        if (empty($poFiles)) {
            return $locales;
        }

        // Get total strings count from .pot file
        $totalStrings = $potFile ? $this->countStringsInPotFile($potFile) : null;

        // Parse each .po file
        foreach ($poFiles as $poFile) {
            $locale = $this->extractLocaleFromFilename($poFile);
            if ($locale === null) {
                continue;
            }

            $stats = $this->parsePoFile($poFile);

            // If we don't have a .pot file, use the total from .po file
            $total = $totalStrings ?? $stats['total'];
            $percentage = $total > 0 ? round(($stats['translated'] / $total) * 100, 2) : 0;

            $locales[$locale] = [
                'name' => $this->getLocaleName($locale),
                'percentage' => $percentage,
                'translated' => $stats['translated'],
                'waiting' => 0,
                'total' => $total,
            ];
        }

        return $locales;
    }

    /**
     * Find the registered translation path from plugin PHP files.
     *
     * @param string $pluginPath
     * @return string|null
     */
    private function findRegisteredTranslationPath(string $pluginPath): ?string
    {
        // Find all PHP files in the plugin root (usually main plugin file is in root)
        $phpFiles = $this->findPhpFiles($pluginPath);
        if (empty($phpFiles)) {
            return null;
        }

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Look for load_plugin_textdomain calls - handle nested parentheses
            if (preg_match('/load_plugin_textdomain\s*\(/is', $content, $startMatch, PREG_OFFSET_CAPTURE)) {
                $startPos = $startMatch[0][1] + strlen($startMatch[0][0]);
                $call = $this->extractBalancedParentheses($content, $startPos);

                if ($call !== null) {
                    // Try to extract the path parameter (3rd parameter)
                    // Split by comma, but respect nested function calls
                    $params = $this->splitParameters($call);

                    if (count($params) >= 3) {
                        $thirdParam = trim($params[2]);

                        // Extract all string literals from the third parameter
                        preg_match_all('/[\'"]([^\'")]+)[\'"]/', $thirdParam, $stringMatches);

                        if (!empty($stringMatches[1])) {
                            // Try each string found (usually the last one is the directory name)
                            foreach (array_reverse($stringMatches[1]) as $path) {
                                $fullPath = $pluginPath . '/' . trim($path, '/');
                                if (is_dir($fullPath)) {
                                    return $fullPath;
                                }
                            }
                        }
                    } elseif (count($params) >= 2) {
                        // If third parameter is not provided, default behavior
                        $commonPaths = ['languages', 'lang', 'i18n'];
                        foreach ($commonPaths as $common) {
                            $fullPath = $pluginPath . '/' . $common;
                            if (is_dir($fullPath)) {
                                return $fullPath;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Count the number of translatable strings in a .pot file.
     *
     * @param string $potFile
     * @return int
     */
    private function countStringsInPotFile(string $potFile): int
    {
        $content = @file_get_contents($potFile);
        if ($content === false) {
            return 0;
        }

        // Count msgid entries (excluding the header)
        preg_match_all('/^msgid\s+"(.+)"/m', $content, $matches);
        $count = count($matches[0]);

        // Subtract the header entry (empty msgid)
        return max(0, $count - 1);
    }

    /**
     * Parse a .po file to extract translation statistics.
     *
     * @param string $poFile
     * @return array{translated: int, total: int}
     */
    private function parsePoFile(string $poFile): array
    {
        $content = @file_get_contents($poFile);
        if ($content === false) {
            return ['translated' => 0, 'total' => 0];
        }

        $stats = ['translated' => 0, 'total' => 0];

        // Split into entries by blank lines
        $entries = preg_split('/\n\n+/', $content);

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }

            // Check if this is a real translation entry (has msgid and msgstr)
            if (!preg_match('/^msgid\s+"(.*)"/m', $entry, $msgidMatch)) {
                continue;
            }

            // Skip the header entry (empty msgid)
            if (empty($msgidMatch[1])) {
                continue;
            }

            $stats['total']++;

            // Check if there's a non-empty msgstr
            if (preg_match('/^msgstr\s+"(.+)"/m', $entry, $msgstrMatch)) {
                if (!empty($msgstrMatch[1])) {
                    $stats['translated']++;
                }
            }
        }

        return $stats;
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

            // Handle string boundaries
            if (($char === '"' || $char === "'") && ($i === 0 || $params[$i - 1] !== '\\')) {
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
                    $result[] = $current;
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
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

    /**
     * Extract locale code from a .po filename.
     *
     * @param string $filename
     * @return string|null
     */
    private function extractLocaleFromFilename(string $filename): ?string
    {
        $basename = basename($filename, '.po');

        // Common patterns:
        // plugin-name-en_US.po
        // plugin-name-en.po
        // en_US.po
        // en.po

        // Try to match locale pattern
        if (preg_match('/[_-]([a-z]{2}(?:[_-][A-Z]{2})?)$/', $basename, $matches)) {
            return str_replace('_', '-', strtolower($matches[1]));
        }

        // If the basename itself is a locale
        if (preg_match('/^([a-z]{2}(?:[_-][A-Z]{2})?)$/', $basename, $matches)) {
            return str_replace('_', '-', strtolower($matches[1]));
        }

        return null;
    }

    /**
     * Get a human-readable name for a locale.
     *
     * @param string $locale
     * @return string
     */
    private function getLocaleName(string $locale): string
    {
        $names = [
            'af' => 'Afrikaans',
            'am' => 'Amharic',
            'an' => 'Aragonese',
            'ar' => 'Arabic',
            'ary' => 'Moroccan Arabic',
            'as' => 'Assamese',
            'az' => 'Azerbaijani',
            'azb' => 'South Azerbaijani',
            'bel' => 'Belarusian',
            'bg-bg' => 'Bulgarian',
            'bn-bd' => 'Bengali (Bangladesh)',
            'bo' => 'Tibetan',
            'bs-ba' => 'Bosnian',
            'ca' => 'Catalan',
            'ceb' => 'Cebuano',
            'ckb' => 'Kurdish (Sorani)',
            'co' => 'Corsican',
            'cs-cz' => 'Czech',
            'cy' => 'Welsh',
            'da-dk' => 'Danish',
            'de-at' => 'German (Austria)',
            'de-ch' => 'German (Switzerland)',
            'de-ch-informal' => 'German (Switzerland, Informal)',
            'de-de' => 'German',
            'de-de-formal' => 'German (Formal)',
            'dsb' => 'Lower Sorbian',
            'dzo' => 'Dzongkha',
            'el' => 'Greek',
            'en-au' => 'English (Australia)',
            'en-ca' => 'English (Canada)',
            'en-gb' => 'English (UK)',
            'en-nz' => 'English (New Zealand)',
            'en-za' => 'English (South Africa)',
            'eo' => 'Esperanto',
            'es-ar' => 'Spanish (Argentina)',
            'es-cl' => 'Spanish (Chile)',
            'es-co' => 'Spanish (Colombia)',
            'es-cr' => 'Spanish (Costa Rica)',
            'es-do' => 'Spanish (Dominican Republic)',
            'es-ec' => 'Spanish (Ecuador)',
            'es-es' => 'Spanish (Spain)',
            'es-gt' => 'Spanish (Guatemala)',
            'es-hn' => 'Spanish (Honduras)',
            'es-mx' => 'Spanish (Mexico)',
            'es-pe' => 'Spanish (Peru)',
            'es-pr' => 'Spanish (Puerto Rico)',
            'es-py' => 'Spanish (Paraguay)',
            'es-uy' => 'Spanish (Uruguay)',
            'es-ve' => 'Spanish (Venezuela)',
            'et' => 'Estonian',
            'eu' => 'Basque',
            'fa-af' => 'Persian (Afghanistan)',
            'fa-ir' => 'Persian',
            'fi' => 'Finnish',
            'fo' => 'Faroese',
            'fr-be' => 'French (Belgium)',
            'fr-ca' => 'French (Canada)',
            'fr-fr' => 'French (France)',
            'fur' => 'Friulian',
            'fy' => 'Frisian',
            'ga' => 'Irish',
            'gd' => 'Scottish Gaelic',
            'gl-es' => 'Galician',
            'gn' => 'Guaraní',
            'gu' => 'Gujarati',
            'ha' => 'Hausa',
            'hau' => 'Hausa',
            'haz' => 'Hazaragi',
            'he-il' => 'Hebrew',
            'hi-in' => 'Hindi',
            'hr' => 'Croatian',
            'hsb' => 'Upper Sorbian',
            'hu-hu' => 'Hungarian',
            'hy' => 'Armenian',
            'id-id' => 'Indonesian',
            'is-is' => 'Icelandic',
            'it-it' => 'Italian',
            'ja' => 'Japanese',
            'jv-id' => 'Javanese',
            'ka-ge' => 'Georgian',
            'kab' => 'Kabyle',
            'kk' => 'Kazakh',
            'km' => 'Khmer',
            'kn' => 'Kannada',
            'ko-kr' => 'Korean',
            'ku' => 'Kurdish (Kurmanji)',
            'ky-ky' => 'Kirghiz',
            'lb-lu' => 'Luxembourgish',
            'lo' => 'Lao',
            'lt-lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'mg-mg' => 'Malagasy',
            'mk-mk' => 'Macedonian',
            'ml-in' => 'Malayalam',
            'mn' => 'Mongolian',
            'mr' => 'Marathi',
            'ms-my' => 'Malay',
            'my-mm' => 'Myanmar (Burmese)',
            'nb-no' => 'Norwegian (Bokmål)',
            'ne-np' => 'Nepali',
            'nl-be' => 'Dutch (Belgium)',
            'nl-nl' => 'Dutch',
            'nl-nl-formal' => 'Dutch (Formal)',
            'nn-no' => 'Norwegian (Nynorsk)',
            'oci' => 'Occitan',
            'or' => 'Oriya',
            'os' => 'Ossetic',
            'pa-in' => 'Punjabi',
            'pl-pl' => 'Polish',
            'ps' => 'Pashto',
            'pt-ao' => 'Portuguese (Angola)',
            'pt-br' => 'Portuguese (Brazil)',
            'pt-pt' => 'Portuguese (Portugal)',
            'pt-pt-ao90' => 'Portuguese (Portugal, AO90)',
            'rhg' => 'Rohingya',
            'ro-ro' => 'Romanian',
            'ru-ru' => 'Russian',
            'sah' => 'Sakha',
            'scn' => 'Sicilian',
            'sd-pk' => 'Sindhi',
            'si-lk' => 'Sinhala',
            'sk-sk' => 'Slovak',
            'skr' => 'Saraiki',
            'sl-si' => 'Slovenian',
            'sna' => 'Shona',
            'sq' => 'Albanian',
            'sr-rs' => 'Serbian',
            'sv-se' => 'Swedish',
            'sw' => 'Swahili',
            'szl' => 'Silesian',
            'ta-in' => 'Tamil',
            'ta-lk' => 'Tamil (Sri Lanka)',
            'tah' => 'Tahitian',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tl' => 'Tagalog',
            'tr-tr' => 'Turkish',
            'tt-ru' => 'Tatar',
            'tuk' => 'Turkmen',
            'ug-cn' => 'Uighur',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'uz-uz' => 'Uzbek',
            'vi' => 'Vietnamese',
            'zh-cn' => 'Chinese (China)',
            'zh-hk' => 'Chinese (Hong Kong)',
            'zh-tw' => 'Chinese (Taiwan)',
        ];

        return $names[$locale] ?? ucfirst(str_replace('-', ' ', $locale));
    }
}
