<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslationScorer
{
    /**
     * The main locales to evaluate translation quality against.
     * Used for informational "global reach" metrics, not for scoring.
     */
    private const MAJOR_LOCALES = [
        'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_BR', 'ja', 'zh_CN', 'nl_NL', 'ru_RU', 'ko_KR',
    ];

    /**
     * Calculate the i18n implementation score and grade.
     * Score is based on code quality, NOT translation coverage.
     *
     * @param int $highIssues
     * @param int $mediumIssues
     * @param int $lowIssues
     * @param float $textDomainConsistency Percentage 0-100
     * @param bool $hasTranslatableStrings Whether plugin has any translatable strings
     * @return array{grade: string, percentage: float, reasoning: string}
     */
    public function calculateScore(
        int $highIssues,
        int $mediumIssues,
        int $lowIssues,
        float $textDomainConsistency,
        bool $hasTranslatableStrings
    ): array
    {
        // If the plugin has no translatable strings, it's not i18n-ready
        if (!$hasTranslatableStrings) {
            return [
                'grade' => 'F',
                'percentage' => 0.0,
                'reasoning' => 'The plugin has no translatable strings. All user-facing text should be wrapped in translation functions.',
            ];
        }

        // Calculate base score from 100, deducting points for issues
        $baseScore = 100.0;

        // High severity issues: -15 points each (critical problems)
        $baseScore -= ($highIssues * 15);

        // Medium severity issues: -5 points each (important problems)
        $baseScore -= ($mediumIssues * 5);

        // Low severity issues: -2 points each (minor problems)
        $baseScore -= ($lowIssues * 2);

        // Text domain consistency affects score (0-10 point bonus)
        // 100% consistency = +10 points, 0% = -10 points
        $consistencyBonus = ($textDomainConsistency / 100 * 20) - 10;
        $baseScore += $consistencyBonus;

        // Ensure score stays within 0-100 range
        $finalScore = max(0.0, min(100.0, $baseScore));

        // Determine grade based on final score
        $grade = match (true) {
            $finalScore >= 95 && $highIssues === 0 && $mediumIssues <= 1 => 'A+',
            $finalScore >= 90 && $highIssues === 0 && $mediumIssues <= 2 => 'A',
            $finalScore >= 80 && $highIssues === 0 => 'B',
            $finalScore >= 70 && $highIssues <= 1 => 'C',
            $finalScore >= 50 => 'D',
            default => 'F',
        };

        // Build reasoning based on grade and issues
        $issuesSummary = [];
        if ($highIssues > 0) {
            $issuesSummary[] = sprintf('%d high severity issue%s', $highIssues, $highIssues === 1 ? '' : 's');
        }
        if ($mediumIssues > 0) {
            $issuesSummary[] = sprintf('%d medium severity issue%s', $mediumIssues, $mediumIssues === 1 ? '' : 's');
        }
        if ($lowIssues > 0) {
            $issuesSummary[] = sprintf('%d low severity issue%s', $lowIssues, $lowIssues === 1 ? '' : 's');
        }

        $reasoning = match ($grade) {
            'A+' => sprintf(
                'Excellent i18n implementation with %.1f%% text domain consistency and minimal issues. The code follows WordPress translation best practices.',
                $textDomainConsistency
            ),
            'A' => sprintf(
                'Very good i18n implementation with %.1f%% text domain consistency. %s detected but overall quality is strong.',
                $textDomainConsistency,
                implode(' and ', $issuesSummary) ?: 'Minor issues'
            ),
            'B' => sprintf(
                'Good i18n implementation with %.1f%% text domain consistency, but improvements needed. Issues: %s.',
                $textDomainConsistency,
                implode(', ', $issuesSummary) ?: 'Some problems detected'
            ),
            'C' => sprintf(
                'Moderate i18n implementation (%.1f%% text domain consistency). Several issues need attention: %s.',
                $textDomainConsistency,
                implode(', ', $issuesSummary) ?: 'Multiple problems'
            ),
            'D' => sprintf(
                'Poor i18n implementation (%.1f%% text domain consistency) with significant issues: %s.',
                $textDomainConsistency,
                implode(', ', $issuesSummary) ?: 'Many problems'
            ),
            'F' => sprintf(
                'Critical i18n implementation problems (%.1f%% text domain consistency). Issues: %s. Major refactoring needed.',
                $textDomainConsistency,
                implode(', ', $issuesSummary) ?: 'Severe problems'
            ),
        };

        return [
            'grade' => $grade,
            'percentage' => round($finalScore, 2),
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Calculate translation coverage metrics (informational only).
     *
     * @param array<string, array<string, mixed>> $locales
     * @return array{
     *   total_locales: int,
     *   compliant_locales: int,
     *   major_locale_coverage: float,
     *   compliant_locale_list: array<string>
     * }
     */
    public function calculateCoverageMetrics(array $locales): array
    {
        $compliantLocales = $this->getCompliantLocales($locales);

        // Filter to only major locales that have translation data
        $majorResults = array_filter(
            $locales,
            fn(mixed $data, string $locale) => $this->isMajorLocale($locale),
            ARRAY_FILTER_USE_BOTH
        );

        $totalPercentage = 0.0;
        foreach ($majorResults as $data) {
            $totalPercentage += (float) $data['percentage'];
        }

        // Average coverage across major locales (0 if none present)
        $averageMajorCoverage = count($majorResults) > 0
            ? round($totalPercentage / count($majorResults), 2)
            : 0.0;

        return [
            'total_locales' => count($locales),
            'compliant_locales' => count($compliantLocales),
            'major_locale_coverage' => $averageMajorCoverage,
            'compliant_locale_list' => $compliantLocales,
        ];
    }

    /**
     * Get list of compliant locales.
     *
     * @param array<string, array<string, mixed>> $locales
     * @return array<string>
     */
    public function getCompliantLocales(array $locales): array
    {
        $list = [];

        foreach ($locales as $locale => $data) {
            if ($data['percentage'] >= 80) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    /**
     * Check if a locale is a major locale.
     *
     * @param string $locale
     * @return bool
     */
    private function isMajorLocale(string $locale): bool
    {
        // Check if the locale is directly in the major list
        if (in_array($locale, self::MAJOR_LOCALES, true)) {
            return true;
        }

        // Also check base language code (de_DE → de, ja → ja)
        $baseLocale = explode('_', $locale)[0];
        return in_array($baseLocale, self::MAJOR_LOCALES, true);
    }
}
