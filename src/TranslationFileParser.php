<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslationFileParser
{
    public function __construct(
        private readonly TranslatableStringScanner $scanner = new TranslatableStringScanner()
    ) {
    }

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

        // Find all .po files (language-specific translations)
        $poFiles = glob($registeredPath . '/*.po');

        if (empty($poFiles)) {
            return $locales;
        }

        // Get text domain from plugin code
        $textDomain = $this->findTextDomain($pluginPath);

        // Extract actual translatable strings from PHP code
        $codeStrings = $this->scanner->extractTranslatableStrings($pluginPath, $textDomain);
        $totalStrings = count($codeStrings);

        // If scanning failed, fallback to .pot file count
        if ($totalStrings === 0) {
            $potFiles = glob($registeredPath . '/*.pot');
            if (!empty($potFiles)) {
                $totalStrings = $this->countStringsInPotFile($potFiles[0]);
            }
        }

        // Parse each .po file
        foreach ($poFiles as $poFile) {
            $locale = $this->extractLocaleFromFilename($poFile);
            if ($locale === null) {
                continue;
            }

            $stats = $this->parsePoFile($poFile);

            // Use actual count from code, or fallback to .po file total
            $total = $totalStrings > 0 ? $totalStrings : $stats['total'];
            $percentage = $total > 0 ? round(($stats['translated'] / $total) * 100, 2) : 0;

            // Identify missing strings by comparing code strings with .po file msgids
            $missingStrings = [];
            if (!empty($codeStrings)) {
                $missingStrings = array_values(array_diff($codeStrings, $stats['msgids']));
            }

            $locales[$locale] = [
                'name' => $this->getLocaleName($locale),
                'percentage' => $percentage,
                'translated' => $stats['translated'],
                'waiting' => 0,
                'total' => $total,
                'untranslated_in_file' => count($stats['untranslated']),
                'missing_from_file' => count($missingStrings),
                'confidence' => $this->calculateConfidence($codeStrings, $stats),
            ];
        }

        return $locales;
    }

    /**
     * Calculate confidence score for translation accuracy.
     *
     * @param array<string> $codeStrings
     * @param array{msgids: array<string>, total: int} $poStats
     * @return string
     */
    private function calculateConfidence(array $codeStrings, array $poStats): string
    {
        if (empty($codeStrings)) {
            return 'low'; // No code strings found to compare
        }

        $codeCount = count($codeStrings);
        $poCount = $poStats['total'];

        // Calculate how well the .po file matches the code
        $matchRatio = $codeCount > 0 ? $poCount / $codeCount : 0;

        if ($matchRatio >= 0.95 && $matchRatio <= 1.05) {
            return 'high'; // .po file matches code strings closely
        } elseif ($matchRatio >= 0.85 && $matchRatio <= 1.15) {
            return 'medium'; // Some discrepancy but reasonable
        } else {
            return 'low'; // Significant mismatch
        }
    }

    /**
     * Find the text domain from plugin PHP files.
     *
     * @param string $pluginPath
     * @return string|null
     */
    private function findTextDomain(string $pluginPath): ?string
    {
        $phpFiles = $this->findPhpFiles($pluginPath);

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile);
            if ($content === false) {
                continue;
            }

            // Look for load_plugin_textdomain calls
            if (preg_match('/load_plugin_textdomain\s*\(/is', $content, $startMatch, PREG_OFFSET_CAPTURE)) {
                $startPos = $startMatch[0][1] + strlen($startMatch[0][0]);
                $call = $this->extractBalancedParentheses($content, $startPos);

                if ($call !== null) {
                    $params = $this->splitParameters($call);

                    if (!empty($params)) {
                        // First parameter is the text domain
                        $textDomain = trim($params[0], " \t\n\r\0\x0B\"'");
                        if (!empty($textDomain)) {
                            return $textDomain;
                        }
                    }
                }
            }

            // Also check plugin header
            if (preg_match('/^\s*\*\s*Text Domain:\s*(.+)$/im', $content, $match)) {
                $textDomain = trim($match[1]);
                if (!empty($textDomain)) {
                    return $textDomain;
                }
            }
        }

        return null;
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
     * @return array{translated: int, total: int, msgids: array<string>, untranslated: array<string>}
     */
    private function parsePoFile(string $poFile): array
    {
        $content = @file_get_contents($poFile);
        if ($content === false) {
            return ['translated' => 0, 'total' => 0, 'msgids' => [], 'untranslated' => []];
        }

        $stats = [
            'translated' => 0,
            'total' => 0,
            'msgids' => [],
            'untranslated' => []
        ];

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

            $msgid = $msgidMatch[1];
            $stats['total']++;
            $stats['msgids'][] = $msgid;

            // Check if there's a non-empty msgstr
            if (preg_match('/^msgstr\s+"(.+)"/m', $entry, $msgstrMatch)) {
                if (!empty($msgstrMatch[1])) {
                    $stats['translated']++;
                } else {
                    $stats['untranslated'][] = $msgid;
                }
            } else {
                $stats['untranslated'][] = $msgid;
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
     * Extract locale code from a .po filename in WordPress format.
     *
     * @param string $filename
     * @return string|null
     */
    private function extractLocaleFromFilename(string $filename): ?string
    {
        $basename = basename($filename, '.po');

        // Common patterns (WordPress format uses underscores):
        // plugin-name-en_US.po
        // plugin-name-de_DE.po
        // plugin-name-pt_BR.po
        // en_US.po
        // de_DE.po
        // ja.po (single locale)

        // Try to match locale pattern: 2-letter language code, optionally followed by underscore and 2-letter country code
        if (preg_match('/[_-]([a-z]{2}(?:_[A-Z]{2})?)$/', $basename, $matches)) {
            return $matches[1];
        }

        // If the basename itself is a locale
        if (preg_match('/^([a-z]{2}(?:_[A-Z]{2})?)$/', $basename, $matches)) {
            return $matches[1];
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
            'bg_BG' => 'Bulgarian',
            'bn_BD' => 'Bengali (Bangladesh)',
            'bo' => 'Tibetan',
            'bs_BA' => 'Bosnian',
            'ca' => 'Catalan',
            'ceb' => 'Cebuano',
            'ckb' => 'Kurdish (Sorani)',
            'co' => 'Corsican',
            'cs_CZ' => 'Czech',
            'cy' => 'Welsh',
            'da_DK' => 'Danish',
            'de_AT' => 'German (Austria)',
            'de_CH' => 'German (Switzerland)',
            'de_CH_informal' => 'German (Switzerland, Informal)',
            'de_DE' => 'German',
            'de_DE_formal' => 'German (Formal)',
            'dsb' => 'Lower Sorbian',
            'dzo' => 'Dzongkha',
            'el' => 'Greek',
            'en_AU' => 'English (Australia)',
            'en_CA' => 'English (Canada)',
            'en_GB' => 'English (UK)',
            'en_NZ' => 'English (New Zealand)',
            'en_ZA' => 'English (South Africa)',
            'eo' => 'Esperanto',
            'es_AR' => 'Spanish (Argentina)',
            'es_CL' => 'Spanish (Chile)',
            'es_CO' => 'Spanish (Colombia)',
            'es_CR' => 'Spanish (Costa Rica)',
            'es_DO' => 'Spanish (Dominican Republic)',
            'es_EC' => 'Spanish (Ecuador)',
            'es_ES' => 'Spanish (Spain)',
            'es_GT' => 'Spanish (Guatemala)',
            'es_HN' => 'Spanish (Honduras)',
            'es_MX' => 'Spanish (Mexico)',
            'es_PE' => 'Spanish (Peru)',
            'es_PR' => 'Spanish (Puerto Rico)',
            'es_PY' => 'Spanish (Paraguay)',
            'es_UY' => 'Spanish (Uruguay)',
            'es_VE' => 'Spanish (Venezuela)',
            'et' => 'Estonian',
            'eu' => 'Basque',
            'fa_AF' => 'Persian (Afghanistan)',
            'fa_IR' => 'Persian',
            'fi' => 'Finnish',
            'fo' => 'Faroese',
            'fr_BE' => 'French (Belgium)',
            'fr_CA' => 'French (Canada)',
            'fr_FR' => 'French (France)',
            'fur' => 'Friulian',
            'fy' => 'Frisian',
            'ga' => 'Irish',
            'gd' => 'Scottish Gaelic',
            'gl_ES' => 'Galician',
            'gn' => 'Guaraní',
            'gu' => 'Gujarati',
            'ha' => 'Hausa',
            'hau' => 'Hausa',
            'haz' => 'Hazaragi',
            'he_IL' => 'Hebrew',
            'hi_IN' => 'Hindi',
            'hr' => 'Croatian',
            'hsb' => 'Upper Sorbian',
            'hu_HU' => 'Hungarian',
            'hy' => 'Armenian',
            'id_ID' => 'Indonesian',
            'is_IS' => 'Icelandic',
            'it_IT' => 'Italian',
            'ja' => 'Japanese',
            'jv_ID' => 'Javanese',
            'ka_GE' => 'Georgian',
            'kab' => 'Kabyle',
            'kk' => 'Kazakh',
            'km' => 'Khmer',
            'kn' => 'Kannada',
            'ko_KR' => 'Korean',
            'ku' => 'Kurdish (Kurmanji)',
            'ky_KY' => 'Kirghiz',
            'lb_LU' => 'Luxembourgish',
            'lo' => 'Lao',
            'lt_LT' => 'Lithuanian',
            'lv' => 'Latvian',
            'mg_MG' => 'Malagasy',
            'mk_MK' => 'Macedonian',
            'ml_IN' => 'Malayalam',
            'mn' => 'Mongolian',
            'mr' => 'Marathi',
            'ms_MY' => 'Malay',
            'my_MM' => 'Myanmar (Burmese)',
            'nb_NO' => 'Norwegian (Bokmål)',
            'ne_NP' => 'Nepali',
            'nl_BE' => 'Dutch (Belgium)',
            'nl_NL' => 'Dutch',
            'nl_NL_formal' => 'Dutch (Formal)',
            'nn_NO' => 'Norwegian (Nynorsk)',
            'oci' => 'Occitan',
            'or' => 'Oriya',
            'os' => 'Ossetic',
            'pa_IN' => 'Punjabi',
            'pl_PL' => 'Polish',
            'ps' => 'Pashto',
            'pt_AO' => 'Portuguese (Angola)',
            'pt_BR' => 'Portuguese (Brazil)',
            'pt_PT' => 'Portuguese (Portugal)',
            'pt_PT_ao90' => 'Portuguese (Portugal, AO90)',
            'rhg' => 'Rohingya',
            'ro_RO' => 'Romanian',
            'ru_RU' => 'Russian',
            'sah' => 'Sakha',
            'scn' => 'Sicilian',
            'sd_PK' => 'Sindhi',
            'si_LK' => 'Sinhala',
            'sk_SK' => 'Slovak',
            'skr' => 'Saraiki',
            'sl_SI' => 'Slovenian',
            'sna' => 'Shona',
            'sq' => 'Albanian',
            'sr_RS' => 'Serbian',
            'sv_SE' => 'Swedish',
            'sw' => 'Swahili',
            'szl' => 'Silesian',
            'ta_IN' => 'Tamil',
            'ta_LK' => 'Tamil (Sri Lanka)',
            'tah' => 'Tahitian',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tl' => 'Tagalog',
            'tr_TR' => 'Turkish',
            'tt_RU' => 'Tatar',
            'tuk' => 'Turkmen',
            'ug_CN' => 'Uighur',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'uz_UZ' => 'Uzbek',
            'vi' => 'Vietnamese',
            'zh_CN' => 'Chinese (China)',
            'zh_HK' => 'Chinese (Hong Kong)',
            'zh_TW' => 'Chinese (Taiwan)',
        ];

        return $names[$locale] ?? ucfirst(str_replace('_', ' ', $locale));
    }
}
