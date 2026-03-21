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
        private readonly UntranslatedStringScanner $untranslatedScanner = new UntranslatedStringScanner()
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

        $compliantLocales = $this->scorer->getCompliantLocales($locales);
        $score = $this->scorer->calculateScore($locales);

        $tableRows = [];
        foreach ($locales as $locale => $data) {
            $tableRows[] = [
                'local' => $locale,
                'name' => $data['name'],
                'percentage' => $data['percentage'] . '%',
            ];
        }

        // Build issues array
        $issues = [];
        if ($untranslatedResult['count'] > 0) {
            $issues[] = [
                'type' => 'untranslated_strings',
                'severity' => 'warning',
                'message' => sprintf(
                    'Found %d user-facing string%s that should be translatable but %s not wrapped in translation functions.',
                    $untranslatedResult['count'],
                    $untranslatedResult['count'] === 1 ? '' : 's',
                    $untranslatedResult['count'] === 1 ? 'is' : 'are'
                ),
                'count' => $untranslatedResult['count'],
                'examples' => array_slice($untranslatedResult['strings'], 0, 5), // Show first 5 examples
            ];
        }

        $presentation = [
            'supported_locales' => $this->reportBuilder->createList(
                'Supported locales (80%+ translated)',
                $compliantLocales
            ),
            'coverage_by_locale' => $this->reportBuilder->createTable(
                'Coverage by locale',
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
            ],
            metrics: [
                'detected' => count($locales),
                'complaints' => count($compliantLocales),
                'untranslated_strings' => $untranslatedResult['count'],
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
