<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerDummy;

use InvalidArgumentException;
use Throwable;

class JobProcessor
{
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

    private function build_report($result): array
    {
        $complaintLocales = $this->getComplaintLocales($result);

        return [
            'score' => [
                'grade' => 'A',
                'percentage' => 95,
                'reasoning' => 'The plugin is well translated with 100% of strings translated and no major issues detected in the major languages.',
            ],
            'metrics' => [
                'detected' => count($result),
                'complaints' => count($complaintLocales),
            ],
            'capabilities' => [
                'supported_locales' => $complaintLocales,
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
                    "items" => $complaintLocales,
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

    private function getComplaintLocales(array $result): array
    {
        $list = [];

        foreach ($result as $locale => $data) {
            if ($data['percentage'] < 80) {
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
