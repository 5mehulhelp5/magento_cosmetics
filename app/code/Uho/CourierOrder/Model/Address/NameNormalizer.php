<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Address;

use function mb_strtolower;
use function preg_quote;
use function preg_replace;
use function str_replace;
use function trim;
/**
 * Normalizes Ukrainian city-name variants for exact-match comparison against
 * perspective_novaposhta_catalog_cities.descriptionua/descriptionru.
 *
 * Only used for city-name matching — never applied to personal names (NameSplitter).
 */
class NameNormalizer
{
    /**
     * Administrative prefixes/suffixes seen in Nova Poshta city-name data variants.
     *
     * @var string[]
     */
    private const array ADMIN_TOKENS = ['м.', 'місто', 'смт', 'с.', 'село'];

    public function normalize(string $name): string
    {
        $normalized = trim($name);
        $normalized = $this->stripRegionQualifier($normalized);
        $normalized = $this->stripAdminTokens($normalized);
        $normalized = $this->normalizeApostrophes($normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = mb_strtolower(trim($normalized), 'UTF-8');

        return $normalized;
    }

    /**
     * Strips trailing region qualifiers in parentheses, e.g. "Бровари (Київська обл.)" -> "Бровари".
     */
    private function stripRegionQualifier(string $name): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $name));
    }

    private function stripAdminTokens(string $name): string
    {
        foreach (self::ADMIN_TOKENS as $token) {
            $pattern = '/^' . preg_quote($token, '/') . '\s+/iu';
            $name = (string) preg_replace($pattern, '', $name);
        }

        return trim($name);
    }

    /**
     * Normalizes apostrophe variants (', ’, ʼ) to a single canonical form —
     * matters for names like Кам'янець-Подільський.
     */
    private function normalizeApostrophes(string $name): string
    {
        return str_replace(["’", "ʼ", "`"], "'", $name);
    }
}
