<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Address;

use Magento\Framework\Phrase;
use Uho\CourierOrder\Model\Exception\InvalidPayloadException;

use function array_shift;
use function count;
use function implode;
use function preg_split;
use function trim;
class NameSplitter
{
    public function split(string $fullName): NameParts
    {
        $tokens = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || count($tokens) < 2) {
            throw new InvalidPayloadException(
                new Phrase('full_name must contain at least a last name and a first name.')
            );
        }

        $lastname = array_shift($tokens);
        $firstname = implode(' ', $tokens);

        return new NameParts($lastname, $firstname);
    }
}
