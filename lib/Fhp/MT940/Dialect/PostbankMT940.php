<?php

namespace Fhp\MT940\Dialect;

use Fhp\MT940\MT940;

class PostbankMT940 extends MT940
{
    public const DIALECT_ID = 'https://hbci.postbank.de/banking/hbci.do';

    /** {@inheritdoc} */
    public function extractStructuredDataFromRemittanceLines($descriptionLines, string &$gvc, array &$rawLines, array $transaction): array
    {
        $structuredStartFound = count($descriptionLines) > 0 && preg_match('/^[A-Z]{4}\+/', array_values($descriptionLines)[0]) === 1;

        $result = parent::extractStructuredDataFromRemittanceLines($descriptionLines, $gvc, $rawLines, $transaction);
        if ($structuredStartFound) {
            return $result;
        }

        // z.B bei Zinsen o.ä. ist alles leer
        if (!isset($descriptionLines[0])) {
            return [];
        }

        // Bie Auslandsüberweisungen (=210)
        // Der Empfänger name steht als erstes im Verwendungszweck und teile des Verwendungszwecks stehen im Namen
        if ($gvc == '210') {
            $name = array_shift($descriptionLines);

            array_unshift($descriptionLines, $rawLines[33]);
            array_unshift($descriptionLines, $rawLines[32]);
            $rawLines[32] = $name;
            $rawLines[33] = '';
        }

        $result['SVWZ'] = implode("\n", $descriptionLines);
        return $result;
    }
}
