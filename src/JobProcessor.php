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
        private readonly TranslationScorer $scorer = new TranslationScorer()
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
        // Only fetch translation data for plugins from wordpress.org
        if ($job->source !== 'wordpress.org') {
            $score = $this->scorer->calculateScore([]);
            return $this->reportBuilder->build(
                score: $score,
                details: ['locales' => []],
                metrics: ['detected' => 0, 'complaints' => 0],
                capabilities: ['supported_locales' => []],
                presentation: []
            );
        }

        $locales = [];

        $url = 'https://translate.wordpress.org/api/projects/wp-plugins/' . urlencode($job->plugin) . '/stable';

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

            $locales[$set['locale']] = [
                'name' => $set['name'],
                'percentage' => $set['percent_translated'],
                'translated' => (int) $set['current_count'],
                'waiting' => (int) $set['waiting_count'],
                'total' => (int) $set['all_count'],
            ];
        }

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

        return $this->reportBuilder->build(
            score: $score,
            details: ['locales' => $locales],
            metrics: [
                'detected' => count($locales),
                'complaints' => count($compliantLocales),
            ],
            capabilities: [
                'supported_locales' => $compliantLocales,
            ],
            presentation: [
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
            ]
        );
    }
}
