<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Services/Stream/QualityResolver.php
 * Fungsi : resolusi kualitas tersedia + fallback chain 1080->720->480->360 + extra keys
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.4, §5, §9.2
 */
declare(strict_types=1);

namespace App\Services\Stream;

final class QualityResolver
{
    /**
     * @var array<int,string>
     */
    private const STANDARD_CHAIN = ['1080', '720', '480', '360'];

    /**
     * @param array<string,mixed> $qualityUrls
     * @return array<int,string>
     */
    public function available(array $qualityUrls): array
    {
        $keys = array_map('strval', array_keys($qualityUrls));
        $standard = array_values(array_filter(
            self::STANDARD_CHAIN,
            static fn (string $quality): bool => in_array($quality, $keys, true)
        ));

        $extra = array_values(array_filter(
            $keys,
            static fn (string $key): bool => !in_array($key, self::STANDARD_CHAIN, true)
        ));

        sort($extra, SORT_NATURAL);

        return array_merge($standard, $extra);
    }

    /**
     * @param array<string,mixed> $qualityUrls
     */
    public function resolve(array $qualityUrls, ?string $requested = null): ?string
    {
        $available = $this->available($qualityUrls);

        if ($available === []) {
            return null;
        }

        $requested = $requested !== null
            ? (string) $requested
            : (string) config('zonaotaku.stream.default_quality', '720');

        if (in_array($requested, $available, true)) {
            return $requested;
        }

        $standardIndex = array_search($requested, self::STANDARD_CHAIN, true);

        if ($standardIndex !== false) {
            for ($i = $standardIndex + 1; $i < count(self::STANDARD_CHAIN); $i++) {
                if (in_array(self::STANDARD_CHAIN[$i], $available, true)) {
                    return self::STANDARD_CHAIN[$i];
                }
            }

            for ($i = $standardIndex - 1; $i >= 0; $i--) {
                if (in_array(self::STANDARD_CHAIN[$i], $available, true)) {
                    return self::STANDARD_CHAIN[$i];
                }
            }
        }

        return $available[0] ?? null;
    }
}
