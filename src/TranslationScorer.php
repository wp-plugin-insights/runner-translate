<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerTranslate;

class TranslationScorer
{
    /**
     * The main locales to evaluate translation quality against.
     */
    private const MAJOR_LOCALES = [
        'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_BR', 'ja', 'zh_CN', 'nl_NL', 'ru_RU', 'ko_KR',
    ];

    /**
     * Calculate the translation score and grade.
     *
     * @param array<string, array<string, mixed>> $locales
     * @return array{grade: string, percentage: float, reasoning: string}
     */
    public function calculateScore(array $locales): array
    {
        $compliantLocales = $this->getCompliantLocales($locales);

        // Filter to only major locales that have translation data
        // Match both 'de' and 'de-de' formats
        $majorResults = array_filter(
            $locales,
            fn(mixed $data, string $locale) => $this->isMajorLocale($locale),
            ARRAY_FILTER_USE_BOTH
        );

        $majorCompliant = array_filter(
            $compliantLocales,
            fn(string $locale) => $this->isMajorLocale($locale)
        );

        $nonMajorCompliant = array_filter(
            $compliantLocales,
            fn(string $locale) => !$this->isMajorLocale($locale)
        );

        // Get base locale codes from found results (de-de → de)
        $foundMajorBaseCodes = array_unique(array_map(
            fn($locale) => explode('-', $locale)[0],
            array_keys($majorResults)
        ));

        // Get base locale codes from compliant locales
        $compliantMajorBaseCodes = array_unique(array_map(
            fn($locale) => explode('-', $locale)[0],
            $majorCompliant
        ));

        $majorNonCompliant = array_diff(self::MAJOR_LOCALES, $compliantMajorBaseCodes);
        $missingMajor = array_diff(self::MAJOR_LOCALES, $foundMajorBaseCodes);
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
            if ($data['percentage'] > 80) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    /**
     * Check if a locale is a major locale (handles both 'de' and 'de-de' formats).
     *
     * @param string $locale
     * @return bool
     */
    private function isMajorLocale(string $locale): bool
    {
        // Normalize the locale code to base language (de-de → de)
        $baseLocale = explode('-', $locale)[0];

        // Check if either the full locale or base locale is in the major list
        return in_array($locale, self::MAJOR_LOCALES, true) ||
               in_array($baseLocale, self::MAJOR_LOCALES, true);
    }
}
