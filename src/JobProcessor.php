<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

use InvalidArgumentException;
use Throwable;

class JobProcessor
{
    public function __construct(
        private readonly Config $config,
        private readonly ReportBuilder $reportBuilder = new ReportBuilder(),
        private readonly TranslationScorer $scorer = new TranslationScorer(),
        private readonly TranslationFileParser $fileParser = new TranslationFileParser(),
        private readonly UntranslatedStringScanner $untranslatedScanner = new UntranslatedStringScanner(),
        private readonly TextDomainValidator $textDomainValidator = new TextDomainValidator(),
        private readonly JavaScriptI18nScanner $jsI18nScanner = new JavaScriptI18nScanner(),
        private readonly LoadHookValidator $loadHookValidator = new LoadHookValidator(),
        private readonly TranslationBestPractices $bestPractices = new TranslationBestPractices()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(string $body): array
    {
        $receivedAt = gmdate(DATE_ATOM);
        $job = $this->parseJob($body);

        return [
            'runner' => $this->config->runnerName,
            'plugin' => $job->plugin,
            'version' => $job->version,
            'source' => $job->source,
            'src' => $job->src,
            'report' => $this->doAction($job),
            'received_at' => $receivedAt,
            'completed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function parseJob(string $body): Job
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Message body is not valid JSON.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new InvalidArgumentException('Message body must decode to a JSON object.');
        }

        return Job::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function doAction(Job $job): array
    {
        $locales = [];
        $translatedStrings = [];

        // For wordpress.org plugins, fetch from translate.wordpress.org
        if ($job->source === 'wordpress.org') {
            $locales = $this->fetchWordPressOrgTranslations($job->plugin);
        } else {
            // For other sources, scan local translation files
            $locales = $this->fileParser->scanPluginDirectory($job->src);
        }

        // Get list of translated strings from the file parser
        $textDomain = $this->fileParser->findTextDomainPublic($job->src);
        $scanner = $this->fileParser->getScanner();
        $translatedStrings = $scanner->extractTranslatableStrings($job->src, $textDomain);

        // Scan for untranslated user-facing strings
        $untranslatedResult = $this->untranslatedScanner->findUntranslatedStrings($job->src, $translatedStrings);

        // Validate text domain usage
        $textDomainValidation = $this->textDomainValidator->validate($job->src, $job->plugin);

        // Scan JavaScript files for i18n
        $jsI18nResult = $this->jsI18nScanner->scan($job->src, $textDomainValidation['declared']);

        // Validate load hook usage
        $loadHookResult = $this->loadHookValidator->validate($job->src);

        // Check translation best practices
        $bestPracticesResult = $this->bestPractices->check($job->src);

        // Build issues object (calculate score after issues are tallied)
        $issuesHigh = 0;
        $issuesMedium = 0;
        $issuesLow = 0;
        $issuesTrivial = 0;
        $topIssues = [];

        // Text domain validation issues
        if (!$textDomainValidation['is_valid']) {
            $isWordPressOrg = $job->source === 'wordpress.org';

            foreach ($textDomainValidation['issues'] as $issue) {
                // Classify by severity
                if (strpos($issue, 'does not match plugin slug') !== false) {
                    // High for wordpress.org, medium for others
                    if ($isWordPressOrg) {
                        $issuesHigh++;
                        $severity = 'high';
                    } else {
                        $issuesMedium++;
                        $severity = 'medium';
                    }
                } elseif (strpos($issue, 'Multiple text domains') !== false) {
                    $issuesMedium++;
                    $severity = 'medium';
                } elseif (strpos($issue, 'variable text domain') !== false) {
                    $issuesMedium++;
                    $severity = 'medium';
                } else {
                    $issuesLow++;
                    $severity = 'low';
                }

                $topIssue = [
                    'code' => 'i18n.text_domain',
                    'message' => $issue,
                    'severity' => $severity,
                ];

                // Add examples for specific issues
                if (!empty($textDomainValidation['mismatches']) && strpos($issue, 'Only') !== false) {
                    $topIssue['examples'] = array_slice($textDomainValidation['mismatches'], 0, 5);
                } elseif (!empty($textDomainValidation['variable_domains']) && strpos($issue, 'variable') !== false) {
                    $topIssue['examples'] = array_slice($textDomainValidation['variable_domains'], 0, 5);
                }

                $topIssues[] = $topIssue;
            }
        }

        // Untranslated strings
        if ($untranslatedResult['count'] > 0) {
            // Count as low severity since they may not all be legitimate issues
            $issuesLow += $untranslatedResult['count'];

            $topIssues[] = [
                'code' => 'i18n.unwrapped_strings',
                'message' => sprintf(
                    '%d string%s %s not translatable',
                    $untranslatedResult['count'],
                    $untranslatedResult['count'] === 1 ? '' : 's',
                    $untranslatedResult['count'] === 1 ? 'is' : 'are'
                ),
                'severity' => 'low',
                'examples' => array_slice($untranslatedResult['strings'], 0, 5),
            ];
        }

        // JavaScript i18n issues
        if ($jsI18nResult['has_js_files']) {
            // Missing wp_set_script_translations
            if ($jsI18nResult['has_translations'] && empty($jsI18nResult['script_registrations'])) {
                $issuesMedium++;
                $topIssues[] = [
                    'code' => 'i18n.js_no_registration',
                    'message' => 'JavaScript translations found but no wp_set_script_translations() calls',
                    'severity' => 'medium',
                ];
            }

            // JavaScript text domain issues
            if (!empty($jsI18nResult['text_domain_issues'])) {
                $issuesLow += count($jsI18nResult['text_domain_issues']);
                $topIssues[] = [
                    'code' => 'i18n.js_wrong_domain',
                    'message' => sprintf(
                        '%d JavaScript translation%s using wrong text domain',
                        count($jsI18nResult['text_domain_issues']),
                        count($jsI18nResult['text_domain_issues']) === 1 ? '' : 's'
                    ),
                    'severity' => 'low',
                    'examples' => array_slice($jsI18nResult['text_domain_issues'], 0, 5),
                ];
            }

            // Untranslated JavaScript strings
            if (!empty($jsI18nResult['untranslated_strings'])) {
                $issuesTrivial += count($jsI18nResult['untranslated_strings']);
                $topIssues[] = [
                    'code' => 'i18n.js_unwrapped_strings',
                    'message' => sprintf(
                        '%d JavaScript string%s may need translation',
                        count($jsI18nResult['untranslated_strings']),
                        count($jsI18nResult['untranslated_strings']) === 1 ? '' : 's'
                    ),
                    'severity' => 'trivial',
                    'examples' => array_slice($jsI18nResult['untranslated_strings'], 0, 5),
                ];
            }
        }

        // Load hook validation issues
        if (!empty($loadHookResult['issues'])) {
            $issuesMedium += count($loadHookResult['issues']);
            foreach ($loadHookResult['issues'] as $issue) {
                $topIssues[] = [
                    'code' => 'i18n.load_hook',
                    'message' => $issue,
                    'severity' => 'medium',
                ];
            }
        }

        // Translation best practices issues
        if ($bestPracticesResult['total_issues'] > 0) {
            // Wrong function usage (high severity)
            if (!empty($bestPracticesResult['wrong_function_usage'])) {
                $issuesHigh += count($bestPracticesResult['wrong_function_usage']);
                $topIssues[] = [
                    'code' => 'i18n.wrong_function',
                    'message' => sprintf(
                        '%d incorrect translation function usage',
                        count($bestPracticesResult['wrong_function_usage'])
                    ),
                    'severity' => 'high',
                    'examples' => array_slice($bestPracticesResult['wrong_function_usage'], 0, 5),
                ];
            }

            // Wrong placeholder usage (medium severity)
            if (!empty($bestPracticesResult['wrong_placeholder'])) {
                $issuesMedium += count($bestPracticesResult['wrong_placeholder']);
                $topIssues[] = [
                    'code' => 'i18n.wrong_placeholder',
                    'message' => sprintf(
                        '%d incorrect sprintf placeholder%s',
                        count($bestPracticesResult['wrong_placeholder']),
                        count($bestPracticesResult['wrong_placeholder']) === 1 ? '' : 's'
                    ),
                    'severity' => 'medium',
                    'examples' => array_slice($bestPracticesResult['wrong_placeholder'], 0, 5),
                ];
            }

            // Missing context (low severity)
            if (!empty($bestPracticesResult['missing_context'])) {
                $issuesLow += count($bestPracticesResult['missing_context']);
                $topIssues[] = [
                    'code' => 'i18n.missing_context',
                    'message' => sprintf(
                        '%d ambiguous string%s without context',
                        count($bestPracticesResult['missing_context']),
                        count($bestPracticesResult['missing_context']) === 1 ? '' : 's'
                    ),
                    'severity' => 'low',
                    'examples' => array_slice($bestPracticesResult['missing_context'], 0, 5),
                ];
            }

            // Missing translator comments (trivial severity)
            if (!empty($bestPracticesResult['missing_translator_comments'])) {
                $issuesTrivial += count($bestPracticesResult['missing_translator_comments']);
                $topIssues[] = [
                    'code' => 'i18n.missing_translator_comment',
                    'message' => sprintf(
                        '%d translation%s with placeholders missing translator comments',
                        count($bestPracticesResult['missing_translator_comments']),
                        count($bestPracticesResult['missing_translator_comments']) === 1 ? '' : 's'
                    ),
                    'severity' => 'trivial',
                    'examples' => array_slice($bestPracticesResult['missing_translator_comments'], 0, 5),
                ];
            }
        }

        $issues = [
            'high' => $issuesHigh,
            'medium' => $issuesMedium,
            'low' => $issuesLow,
            'trivial' => $issuesTrivial,
            'top' => $topIssues,
        ];

        // Calculate i18n implementation score (based on code quality, not translation coverage)
        $hasTranslatableStrings = count($translatedStrings) > 0;
        $score = $this->scorer->calculateScore(
            $issuesHigh,
            $issuesMedium,
            $issuesLow,
            $textDomainValidation['consistency_score'],
            $hasTranslatableStrings
        );

        // Calculate translation coverage metrics (informational only)
        $coverageMetrics = $this->scorer->calculateCoverageMetrics($locales);
        $compliantLocales = $coverageMetrics['compliant_locale_list'];

        $tableRows = [];
        foreach ($locales as $locale => $data) {
            $tableRows[] = [
                'local' => $locale,
                'name' => $data['name'],
                'percentage' => $data['percentage'] . '%',
            ];
        }

        $presentation = [
            'supported_locales' => $this->reportBuilder->createList(
                'Supported locales (80%+ translated)',
                $compliantLocales
            ),
            'coverage_by_locale' => $this->reportBuilder->createTable(
                'Translation Coverage by Locale (informational)',
                [
                    ['key' => 'local', 'label' => 'Locale'],
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'percentage', 'label' => 'Coverage %'],
                ],
                $tableRows
            ),
        ];

        // Add untranslated strings table if there are any
        if ($untranslatedResult['count'] > 0) {
            $untranslatedRows = [];
            foreach (array_slice($untranslatedResult['strings'], 0, 10) as $item) {
                $untranslatedRows[] = [
                    'string' => strlen($item['string']) > 50
                        ? substr($item['string'], 0, 47) . '...'
                        : $item['string'],
                    'file' => $item['file'],
                    'line' => $item['line'],
                ];
            }

            $presentation['untranslated_strings'] = $this->reportBuilder->createTable(
                'Untranslated Strings (first 10)',
                [
                    ['key' => 'string', 'label' => 'String'],
                    ['key' => 'file', 'label' => 'File'],
                    ['key' => 'line', 'label' => 'Line'],
                ],
                $untranslatedRows
            );
        }

        return $this->reportBuilder->build(
            score: $score,
            details: [
                'locales' => $locales,
                'untranslated_strings' => $untranslatedResult,
                'text_domain' => $textDomainValidation,
                'javascript_i18n' => $jsI18nResult,
                'load_hook' => $loadHookResult,
                'best_practices' => $bestPracticesResult,
            ],
            metrics: [
                // Code quality metrics (affect score)
                'has_translatable_strings' => $hasTranslatableStrings,
                'text_domain_consistency' => $textDomainValidation['consistency_score'],
                'text_domain_valid' => $textDomainValidation['is_valid'],
                'issues_high' => $issuesHigh,
                'issues_medium' => $issuesMedium,
                'issues_low' => $issuesLow,
                'issues_trivial' => $issuesTrivial,
                'untranslated_strings' => $untranslatedResult['count'],
                'js_has_translations' => $jsI18nResult['has_translations'],
                'js_total_strings' => $jsI18nResult['total_js_strings'],
                'js_translated' => $jsI18nResult['total_translated'],
                'load_hook_has_call' => $loadHookResult['has_load_call'],
                'load_hook_issues' => count($loadHookResult['issues']),
                'best_practices_issues' => $bestPracticesResult['total_issues'],
                // Translation coverage metrics (informational only, do not affect score)
                'translation_locales_detected' => $coverageMetrics['total_locales'],
                'translation_locales_compliant' => $coverageMetrics['compliant_locales'],
                'translation_major_locale_coverage' => $coverageMetrics['major_locale_coverage'],
            ],
            capabilities: [
                'supported_locales' => $compliantLocales,
            ],
            presentation: $presentation,
            issues: $issues
        );
    }

    /**
     * Fetch translation data from translate.wordpress.org
     *
     * @param string $pluginSlug
     * @return array<string, array<string, mixed>>
     */
    private function fetchWordPressOrgTranslations(string $pluginSlug): array
    {
        $locales = [];
        $url = 'https://translate.wordpress.org/api/projects/wp-plugins/' . urlencode($pluginSlug) . '/stable';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Failed to fetch translation data: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Translation API returned HTTP ' . $httpCode);
        }

        try {
            $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new \RuntimeException('Translation API response is not valid JSON.', previous: $exception);
        }

        foreach ($data['translation_sets'] as $set) {
            if ($set['current_count'] == 0 && $set['waiting_count'] == 0) {
                continue; // Skip languages with no translations
            }

            $locales[$set['wp_locale']] = [
                'name' => $set['name'],
                'percentage' => $set['percent_translated'],
                'translated' => (int) $set['current_count'],
                'waiting' => (int) $set['waiting_count'],
                'total' => (int) $set['all_count'],
            ];
        }

        return $locales;
    }
}
