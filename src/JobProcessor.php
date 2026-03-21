<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

use InvalidArgumentException;
use Throwable;

class JobProcessor
{
    /**
     * The main locales to evaluate translation quality against.
     */
    private const MAJOR_LOCALES = [
        'de', 'fr', 'es', 'it', 'pt-br', 'ja', 'zh-cn', 'nl', 'ru', 'ko',
    ];

    public function __construct(
        private readonly Config $config
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
        $result = [];

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

            $result[$set['locale']] = [
                'name' => $set['name'],
                'percentage' => $set['percent_translated'],
                'translated' => (int) $set['current_count'],
                'waiting' => (int) $set['waiting_count'],
                'total' => (int) $set['all_count'],
            ];
        }

        return $this->build_report($result);
    }

    private function build_report(array $result): array
    {
        $compliantLocales = $this->getCompliantLocales($result);
        $score = $this->calculateScore($result, $compliantLocales);

        return [
            'score' => $score,
            'metrics' => [
                'detected' => count($result),
                'complaints' => count($compliantLocales),
            ],
            'capabilities' => [
                'supported_locales' => $compliantLocales,
            ],
            "issues" => [

            ],
            "details" => [
                'locales' => $result,
            ],
            "presentation" => [
                "supported_locales" => [
                    "type" => "list",
                    "label" => "Supported locales (80%+ translated)",
                    "items" => $compliantLocales,
                    "display" => 'badges',
                ],
                "coverage_by_locale" => [
                    "type" => "table",
                    "label" => "Coverage by locale",
                    "columns" => [
                        ["key" => "local", "label" => "Locale"],
                        ["key" => "name", "label" => "Name"],
                        ["key" => "percentage", "label" => "Coverage %"],
                    ],
                    "rows" => $this->build_table_rows($result)
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $result
     * @param array<string> $compliantLocales
     * @return array{grade: string, percentage: float, reasoning: string}
     */
    private function calculateScore(array $result, array $compliantLocales): array
    {
        // Filter to only major locales that have translation data
        $majorResults = array_filter(
            $result,
            fn(mixed $data, string $locale) => in_array($locale, self::MAJOR_LOCALES, true),
            ARRAY_FILTER_USE_BOTH
        );

        $majorCompliant = array_filter(
            $compliantLocales,
            fn(string $locale) => in_array($locale, self::MAJOR_LOCALES, true)
        );

        $nonMajorCompliant = array_filter(
            $compliantLocales,
            fn(string $locale) => !in_array($locale, self::MAJOR_LOCALES, true)
        );

        $majorNonCompliant = array_diff(self::MAJOR_LOCALES, $majorCompliant);
        $missingMajor = array_diff(self::MAJOR_LOCALES, array_keys($majorResults));
        $belowThreshold = array_diff($majorNonCompliant, $missingMajor);

        if (count($majorResults) === 0) {
            return [
                'grade' => 'F',
                'percentage' => 0.0,
                'reasoning' => 'No translation data available for any of the major locales (' . implode(', ', self::MAJOR_LOCALES) . ').',
            ];
        }

        $totalPercentage = 0.0;
        foreach ($majorResults as $data) {
            $totalPercentage += (float) $data['percentage'];
        }

        // Score is based on coverage of all major locales, not just those with data
        $averagePercentage = round($totalPercentage / count(self::MAJOR_LOCALES), 2);
        $compliantRatio = count($majorCompliant) / count(self::MAJOR_LOCALES) * 100;

        $grade = match (true) {
            $averagePercentage >= 90 && $compliantRatio >= 80 => 'A',
            $averagePercentage >= 75 && $compliantRatio >= 60 => 'B',
            $averagePercentage >= 50 && $compliantRatio >= 40 => 'C',
            $averagePercentage >= 25 => 'D',
            default => 'F',
        };

        // Promote to A+ when the plugin qualifies for A and has 20+ additional compliant locales beyond the major ones
        if ($grade === 'A' && count($nonMajorCompliant) >= 20) {
            $grade = 'A+';
        }

        $majorLocaleCount = count(self::MAJOR_LOCALES);
        $majorDataCount = count($majorResults);
        $majorCompliantCount = count($majorCompliant);
        $nonMajorCompliantCount = count($nonMajorCompliant);

        $issueDetail = '';
        if (count($belowThreshold) > 0) {
            $issueDetail .= ' Locales below 80%: ' . implode(', ', array_values($belowThreshold)) . '.';
        }
        if (count($missingMajor) > 0) {
            $issueDetail .= ' Missing locales: ' . implode(', ', array_values($missingMajor)) . '.';
        }

        $reasoning = match ($grade) {
            'A+' => sprintf(
                'The plugin is exceptionally well translated with an average of %.1f%% coverage across %d major locales (%d of %d present), %d out of %d major locales are above 80%% translated, and %d additional locales also exceed 80%%.',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $majorCompliantCount, $majorLocaleCount, $nonMajorCompliantCount
            ),
            'A' => sprintf(
                'The plugin is well translated with an average of %.1f%% coverage across %d major locales (%d of %d present), and %d out of %d are above 80%% translated.',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $majorCompliantCount, $majorLocaleCount
            ),
            'B' => sprintf(
                'The plugin has good translation coverage with an average of %.1f%% across %d major locales (%d of %d present), but some still need improvement.%s',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $issueDetail
            ),
            'C' => sprintf(
                'The plugin has moderate translation coverage with an average of %.1f%% across %d major locales (%d of %d present). Many need additional translations.%s',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $issueDetail
            ),
            'D' => sprintf(
                'The plugin has limited translation coverage with an average of %.1f%% across %d major locales (%d of %d present). Most need significant work.%s',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $issueDetail
            ),
            'F' => sprintf(
                'The plugin has very poor translation coverage with an average of %.1f%% across %d major locales (%d of %d present).%s',
                $averagePercentage, $majorLocaleCount, $majorDataCount, $majorLocaleCount, $issueDetail
            ),
        };

        return [
            'grade' => $grade,
            'percentage' => $averagePercentage,
            'reasoning' => $reasoning,
        ];
    }

    private function getCompliantLocales(array $result): array
    {
        $list = [];

        foreach ($result as $locale => $data) {
            if ($data['percentage'] > 80) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    private function build_table_rows(array $result): array
    {
        $rows = [];

        foreach ($result as $locale => $data) {
            $rows[] = [
                'local' => $locale,
                'name' => $data['name'],
                'percentage' => $data['percentage'] . '%',
            ];
        }

        return $rows;
    }
}
