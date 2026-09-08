<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Region\Config;

use DOMDocument;
use DOMElement;
use DOMNode;
use Magento\Framework\Config\ConverterInterface;

use function mb_strtolower;
use function trim;
class Converter implements ConverterInterface
{
    public const string KEY_AREAS = 'areas';
    public const string KEY_CITY_OVERRIDES = 'city_overrides';

    private const string NODE_AREAS = 'areas';
    private const string NODE_AREA = 'area';
    private const string NODE_CITY_OVERRIDES = 'cityOverrides';
    private const string NODE_CITY_OVERRIDE = 'cityOverride';
    private const string ATTR_NAME = 'name';
    private const string ATTR_CITY_REF = 'cityRef';
    private const string ATTR_REGION_CODE = 'regionCode';

    /**
     * @param DOMDocument $source
     * @return array{areas: array<string, string>, city_overrides: array<string, string>}
     */
    public function convert($source): array
    {
        $result = [
            self::KEY_AREAS => [],
            self::KEY_CITY_OVERRIDES => [],
        ];

        $root = $source->documentElement;
        if ($root === null) {
            return $result;
        }

        foreach ($this->elementChildren($root) as $group) {
            match ($group->nodeName) {
                self::NODE_AREAS => $result[self::KEY_AREAS] = $this->collect(
                    $group,
                    self::NODE_AREA,
                    self::ATTR_NAME,
                    $result[self::KEY_AREAS],
                    false,
                ),
                self::NODE_CITY_OVERRIDES => $result[self::KEY_CITY_OVERRIDES] = $this->collect(
                    $group,
                    self::NODE_CITY_OVERRIDE,
                    self::ATTR_CITY_REF,
                    $result[self::KEY_CITY_OVERRIDES],
                    true,
                ),
                default => null,
            };
        }

        return $result;
    }

    /**
     * @param array<string, string> $carry
     * @return array<string, string>
     */
    private function collect(
        DOMElement $group,
        string $childName,
        string $keyAttribute,
        array $carry,
        bool $lowercaseKey,
    ): array {
        foreach ($this->elementChildren($group) as $child) {
            if ($child->nodeName !== $childName) {
                continue;
            }

            $key = trim((string) $child->getAttribute($keyAttribute));
            $regionCode = trim((string) $child->getAttribute(self::ATTR_REGION_CODE));
            if ($key === '' || $regionCode === '') {
                continue;
            }

            $carry[$lowercaseKey ? mb_strtolower($key) : $key] = $regionCode;
        }

        return $carry;
    }

    /**
     * @return DOMElement[]
     */
    private function elementChildren(DOMNode $node): array
    {
        $elements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        return $elements;
    }
}
