<?php

namespace Fhp\MT940;

/**
 * Data format: MT 940 (Version SRG 2001)
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Finanzdatenformate_2010-08-06_final_version.pdf
 * Section: B.8
 */
class MT940
{
    public const CD_CREDIT = 'credit';
    public const CD_DEBIT = 'debit';

    /**
     * @throws MT940Exception
     */
    public function parse(string $rawData): array
    {
        // The divider can be either \r\n or @@
        $divider = substr_count($rawData, "\r\n-") > substr_count($rawData, '@@-') ? "\r\n" : '@@';

        $booked = true;
        $result = [];
        $days = explode($divider . '-', $rawData);
        $soaDate = null;
        foreach ($days as &$day) {
            $day = explode($divider . ':', $day);

            for ($i = 0, $cnt = count($day); $i < $cnt; ++$i) {
                if (preg_match("/\+\@[0-9]+\@$/", trim($day[$i]))) {
                    $booked = false;
                }

                // handle start balance
                // 60F:C160401EUR1234,56
                if (preg_match('/^60(F|M):/', $day[$i])) {
                    // remove 60(F|M): for better parsing
                    $day[$i] = substr($day[$i], 4);
                    $soaDate = $this->getDate(substr($day[$i], 1, 6));

                    // if this statement date ist first seen set start_balance
                    // Note: all further transactions in different statements with the same soaDate will be appended
                    // there will be no new statement done for them. With bigger code changes this could be changed.
                    // For now this is shortcutted like this for fixing https://github.com/nemiah/phpFinTS/issues/367
                    if (!isset($result[$soaDate])) {
                        $result[$soaDate] = ['start_balance' => []];
                        $cdMark = substr($day[$i], 0, 1);
                        if ($cdMark === 'C') {
                            $result[$soaDate]['start_balance']['credit_debit'] = static::CD_CREDIT;
                        } elseif ($cdMark === 'D') {
                            $result[$soaDate]['start_balance']['credit_debit'] = static::CD_DEBIT;
                        }

                        $amount = str_replace(',', '.', substr($day[$i], 10));
                        $result[$soaDate]['start_balance']['amount'] = $amount;
                    }
                } elseif (
                    // found transaction
                    // trx:61:1603310331DR637,39N033NONREF
                    str_starts_with($day[$i], '61:')
                    && isset($day[$i + 1])
                    && str_starts_with($day[$i + 1], '86:')
                ) {
                    $transaction = substr($day[$i], 3);
                    $description = substr($day[$i + 1], 3);

                    if (!isset($result[$soaDate]['transactions'])) {
                        $result[$soaDate]['transactions'] = [];
                    }

                    // short form for better handling
                    $trx = &$result[$soaDate]['transactions'];

                    preg_match('/^\d{6}(\d{4})?(C|D|RC|RD)([A-Z]{1})?([^N]+)N/', $transaction, $trxMatch);
                    if ($trxMatch[2] === 'C' || $trxMatch[2] === 'RC') {
                        $trx[count($trx)]['credit_debit'] = static::CD_CREDIT;
                    } elseif ($trxMatch[2] === 'D' || $trxMatch[2] === 'RD') {
                        $trx[count($trx)]['credit_debit'] = static::CD_DEBIT;
                    } else {
                        throw new MT940Exception('cd mark not found in: ' . $transaction);
                    }

                    $trx[count($trx) - 1]['is_storno'] = ($trxMatch[2] === 'RC' or $trxMatch[2] === 'RD');

                    $amount = $trxMatch[4];
                    $amount = str_replace(',', '.', $amount);
                    $trx[count($trx) - 1]['amount'] = $amount;

                    // :61:1605110509D198,02NMSCNONREF
                    // 16 = year
                    // 0511 = valuta date
                    // 0509 = booking date

                    $year = substr($transaction, 0, 2);
                    $valutaDate = $this->getDate($year . substr($transaction, 2, 4));
                    $bookingDatePart = substr($transaction, 6, 4);

                    if (preg_match('/^\d{4}$/', $bookingDatePart) === 1) {
                        // try to guess the correct year of the booking date

                        $valutaDateTime = new \DateTime($valutaDate);
                        $bookingDateTime = new \DateTime($this->getDate($year . $bookingDatePart));

                        // the booking date can be before or after the valuata date
                        // and one of them can be in another year for example 12-31 and 01-01

                        $diff = $valutaDateTime->diff($bookingDateTime);

                        // if diff is more than half a year
                        if ($diff->days > 182) {
                            // and positive
                            if ($diff->invert === 0) {
                                // its in the last year
                                --$year;
                            }
                            // and negative
                            else {
                                // its in the next year
                                ++$year;
                            }
                        }
                        $bookingDate = $this->getDate($year . $bookingDatePart);
                    } else {
                        // if booking date not set in :61, then we have to take it from :60F
                        $bookingDate = $soaDate;
                    }

                    $trx[count($trx) - 1]['booking_date'] = $bookingDate;
                    $trx[count($trx) - 1]['valuta_date'] = $valutaDate;
                    $trx[count($trx) - 1]['booked'] = $booked;

                    $trx[count($trx) - 1]['description'] = $this->parseDescription($description, $trx[count($trx) - 1]);
                } elseif (
                    preg_match('/^62F:/', $day[$i]) // handle end balance
                ) {
                    // remove 62F: for better parsing
                    $day[$i] = substr($day[$i], 4);
                    $soaDate = $this->getDate(substr($day[$i], 1, 6));

                    if (isset($result[$soaDate])) {
                        #$result[$soaDate] = ['end_balance' => []];

                        $amount = str_replace(',', '.', substr($day[$i], 10, -1));
                        $cdMark = substr($day[$i], 0, 1);
                        if ($cdMark == 'C') {
                            $result[$soaDate]['end_balance']['credit_debit'] = static::CD_CREDIT;
                        } elseif ($cdMark == 'D') {
                            $result[$soaDate]['end_balance']['credit_debit'] = static::CD_DEBIT;
                            $amount *= -1;
                        }

                        $result[$soaDate]['end_balance']['amount'] = $amount;
                    }
                }
            }
        }
        return $result;
    }

    private function isChunked(string $content, int $startIndex) {
        // check if chunked at all
        // only allowed chunk terminators are \r\n (not @@)
        $currentIndex = $startIndex;
        while ($currentIndex < mb_strlen($content)) {
            if (mb_substr($content, $currentIndex, 2) !== "\r\n") {
                return false; // not chunked
            }
            $currentIndex += 67; // 65 bytes + \r\n
        }
        return true;
    }

    /**
     * :86: is expected to consist of lines (chunks) with a length of 65 bytes.
     * This method merges those chunks to a single line.
     * @param string $descr
     * @return string
     */
    protected function dechunkDescription(string $descr): string {
        // some banks start counting after :86:, others include it when counting chunks:
        if ($this->isChunked($descr, 61)) {
            // 65 byte chunks including :86:
            // i. e. sparkasse
            $descr = ':86:' . $descr;
            $offset = 4;
        } else if ($this->isChunked($descr, 65)) {
            // 65 byte chunks starting after :86:
            // see Consors test data
            $offset = 0;
        } else {
            // not chunked at all
            return $descr;
        }

        $result = '';
        $currentIndex = 0;
        $currentChunkLength = 0;
        for ($currentIndex = 0; $currentIndex < mb_strlen($descr); $currentIndex++) {
            $currentChunkLength++;
            if ($currentIndex < $offset) continue; // skip offset for :86:
            $c = mb_substr($descr, $currentIndex, 1);
            if ($currentChunkLength <= 65) {
                $result .= $c;
            } else {
                // end of chunk reached, skip chars (\r\n) until \n is reached:
                if ($c === "\n") {
                    $currentChunkLength = 0;
                }
            }
        }
        return trim($result); // trim removes remaining line breaks
    }

    /**
     * Parses all description fields into a hashed array.
     * @param mixed $descr
     * @param mixed $transaction
     * @return array Fields are utf-8 with \n line breaks.
     */
    protected function parseDescription($descr, $transaction): array
    {
        // we need to make this utf-8 mb safe, because some banks use and count utf-8 chars, even though the spec explicitly states bytes for lengths.
        $encoding = mb_detect_encoding($descr);
        if ($encoding === false || $encoding === 'ascii') $encoding = 'iso-8859-1'; // fallback to extended ascii with german charset
        if ($encoding !== 'utf-8') {
            // might as well do everything in utf-8, then:
            $descr = iconv($encoding, 'utf-8', $descr);
        }

        // Business transaction code
        $gvc = substr($descr, 0, 3);

        $prepared = [];
        $result = [];

        // prefill with empty values
        for ($i = 0; $i <= 63; ++$i) {
            $prepared[$i] = null;
        }

        $descr = $this->dechunkDescription($descr);

        preg_match_all('/\?(\d{2})([^\?]+)/', $descr, $matches, PREG_SET_ORDER);

        $descriptionLines = [];
        foreach ($matches as $m) {
            $index = (int) $m[1];
            // Each subfield identifier signals a new logical line section. The line ends at the latest after the permissible maximum number of characters:
            if ((20 <= $index && $index <= 29) || (60 <= $index && $index <= 63)) {
                $descriptionLines[$index] = $m[2];
            }
            $prepared[$index] = $m[2];
        }

        $description = $this->extractStructuredDataFromRemittanceLines($descriptionLines, $gvc, $prepared, $transaction);

        $description_1 = $description['description_1'];
        $description_2 = $description['description_2'];
        unset($description['description_1']);
        unset($description['description_2']);

        $result['booking_code'] = $gvc;
        $result['booking_text'] = trim($prepared[0] ?? '');
        $result['description'] = $description;
        $result['primanoten_nr'] = trim($prepared[10] ?? '');
        $result['description_1'] = $description_1;
        $result['bank_code'] = trim($prepared[30] ?? '');
        $result['account_number'] = trim($prepared[31] ?? '');
        $result['name'] = trim(($prepared[32] ?? '') . ($prepared[33] ?? ''));
        $result['text_key_addition'] = trim($prepared[34] ?? '');
        $result['description_2'] = $description_2;
        $result['desc_lines'] = array_values($descriptionLines);

        return $result;
    }

    /**
     *
     * The naming of this method is not entirely correct, as it also handles the case of unstructured purpose codes.
     * Each structured identifier (e.g. EREF+) must be at the beginning of a subfield.
     * If no identifier is present in the first line, it is unstructured data.
     * If the length is exceeded, it continues in the following subfield without repeating the identifier.
     * When the identifier changes, a new subfield must be started.
     *
     * All returned description fields contain PHP_EOL ("\n") as line breaks, where permitted.
     *
     * @param string[] $descriptionLines that contain the remittance information
     * @param string $gvc Business transaction code; Out-Parameter, might be changed from information in remittance info
     * @param string[] $rawLines All the lines in the Multi-Purpose-Field 86; Out-Parameter, might be changed from information in remittance info
     * @return array Values of the structured parts (or with key 'SVWZ' also for the unstructured non-SEPA purpose) as well as description_1 and description_2 for the two blocks (20 - 29 and 60 - 63)
     */
    protected function extractStructuredDataFromRemittanceLines($descriptionLines, string &$gvc, array &$rawLines, array $transaction): array
    {
        $parts20 = array();
        $parts60 = array();
        foreach ($descriptionLines as $index => $value) {
            if ($index >= 60) {
                $parts60[$index] = $value;
            } else {
                $parts20[$index] = $value;
            }
        }

        $first = array_values($descriptionLines)[0];
        if (empty($descriptionLines) || strlen($first) < 5 || $first[4] !== '+') {
            // No SEPA identifier ("ABCD+") in the first line.
            // If there is no SEPA identifier in the first line, no more can come, according to spec.
            // Some bank still do, but they get a deviating MT940 prozessor, see SpardaMT940.php for example.
            // -> Normal lines (foreign or fee information, exchange rate, free texts ...)
            // Here all line breaks are removed by maximum segment widths, lines are joined with newline and we're done:
            return array(
                'SVWZ' =>          implode(PHP_EOL, array_map(fn ($s) => preg_replace('/[\n\r]/s', '', $s), $descriptionLines)),
                'description_1' => implode(PHP_EOL, array_map(fn ($s) => preg_replace('/[\n\r]/s', '', $s), $parts20)),
                'description_2' => implode(PHP_EOL, array_map(fn ($s) => preg_replace('/[\n\r]/s', '', $s), $parts60)),
            );
        }

        // structured part
        // here, line breaks in the text are allowed

        // keep those, for whoever needs them
        $result = array(
            'description_1' => implode('', $parts20),
            'description_2' => implode('', $parts60),
        );
        $lastType = null;

        foreach ($descriptionLines as $index => $line) {
            if (strlen($line) >= 5 && $line[4] === '+') {
                $lastType = substr($line, 0, 4);
                // each identifier occurs only once, first value can therefore be assigned directly:
                $result[$lastType] = substr($line, 5);
            } else {
                // no new identifier, continue previous line with spaces
                // The specification says quite clearly that one should join here without spaces.
                // However, many banks behave inconsistently here, which is why spaces can be lost.
                // see MT940Test.php for examples
                $result[$lastType] .= $line;
            }
        }
        foreach ($result as $type => $line) {
            $result[$type] = trim(str_replace("\r\n", PHP_EOL, $line));
        }

        return $result;
    }

    protected function getDate(string $val): string
    {
        $val = '20' . $val;
        preg_match('/(\d{4})(\d{2})(\d{2})/', $val, $m);
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
}