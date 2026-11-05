<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/** Daily euro reference rates from the European Central Bank (1 EUR = N units of each currency). Not final so tests can replace it. */
class EcbRates
{
    private const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /**
     * @return array{date: string, rates: array<string, float>}
     * @throws RuntimeException when the feed can't be read
     */
    public function fetch(): array
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $xml = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($xml) || $status !== 200) {
            throw new RuntimeException('Could not download the ECB rates: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }

        return self::parse($xml);
    }

    /**
     * @return array{date: string, rates: array<string, float>}
     * @throws RuntimeException
     */
    public static function parse(string $xml): array
    {
        preg_match_all("/<Cube\\s+currency=['\"]([A-Z]{3})['\"]\\s+rate=['\"]([0-9.]+)['\"]/", $xml, $m, PREG_SET_ORDER);
        $rates = [];
        foreach ($m as $row) {
            if ((float) $row[2] > 0) {
                $rates[$row[1]] = (float) $row[2];
            }
        }
        if ($rates === []) {
            throw new RuntimeException('The ECB feed had no rates.');
        }
        $date = preg_match("/<Cube\\s+time=['\"](\\d{4}-\\d{2}-\\d{2})['\"]/", $xml, $d) === 1 ? $d[1] : date('Y-m-d');

        return ['date' => $date, 'rates' => $rates];
    }
}
