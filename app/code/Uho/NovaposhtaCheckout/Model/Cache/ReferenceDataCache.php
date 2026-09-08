<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Cache;

use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

use function array_map;
use function hash;
use function implode;
use function is_array;
use function is_string;
/**
 * Read-through cache for the locally cron-synced Nova Poshta reference tables.
 *
 * The catalog tables only change when the Perspective_NovaposhtaCatalog cron runs, so entries
 * are held for a day and invalidated by tag. Empty result sets are cached as well: that is what
 * keeps a flood of nonsense queries from repeatedly scanning 54k warehouse rows.
 */
class ReferenceDataCache
{
    public const string CACHE_TAG = 'UHO_NP_REFERENCE';

    private const string CACHE_ID_PREFIX = 'uho_np_reference_';
    private const int LIFETIME = 86400;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, int|string> $keyParts
     * @param callable(): array<int, array<string, string>> $loader
     * @return array<int, array<string, string>>
     */
    public function get(array $keyParts, callable $loader): array
    {
        $cacheId = $this->buildCacheId($keyParts);
        $cached = $this->cache->load($cacheId);

        if (is_string($cached) && $cached !== '') {
            $decoded = $this->decode($cached, $cacheId);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $data = $loader();
        $this->cache->save($this->serializer->serialize($data), $cacheId, [self::CACHE_TAG], self::LIFETIME);

        return $data;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function decode(string $cached, string $cacheId): ?array
    {
        try {
            $decoded = $this->serializer->unserialize($cached);
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning(
                'Uho_NovaposhtaCheckout: discarding unreadable reference cache entry.',
                ['cache_id' => $cacheId, 'exception' => $exception],
            );

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Hashing keeps user-supplied search terms out of the cache identifier entirely, so no
     * input can collide with, or inject into, another entry's key.
     *
     * @param array<int, int|string> $keyParts
     */
    private function buildCacheId(array $keyParts): string
    {
        return self::CACHE_ID_PREFIX . hash('sha256', implode('|', array_map('strval', $keyParts)));
    }
}
