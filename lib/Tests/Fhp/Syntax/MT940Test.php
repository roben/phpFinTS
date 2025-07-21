<?php

class TestableMT940 extends \Fhp\MT940\MT940
{
    public function parseDescription($descr, $transaction = array()): array
    {
        return parent::parseDescription($descr, $transaction);
    }

    public function dechunkDescription($descr): string
    {
        return parent::dechunkDescription($descr);
    }
}

class MT940Test extends \PHPUnit\Framework\TestCase
{
    private TestableMT940 $mt940;

    protected function setUp(): void
    {
        $this->mt940 = new TestableMT940();
    }

    public function testParsesAuslandsegschaeft()
    {
        $raw = <<<EOF
201?00AUSLANDSGESCHAEFT?107018?20Ref..      1231231231231231?\r
21Betrag CZK           741,00?22Kurs   EUR/CZK    23,355000?23EUR\r
-Ggw.              31,73?24Entgeltinformationen finden?25Sie auf \r
der sep. Rechnung.?26RK 13.07.23?3057000000?319000000000?321/HANS\r
 WURST?34888
EOF;

        $expected_lines = <<<EOF
Ref..      1231231231231231
Betrag CZK           741,00
Kurs   EUR/CZK    23,355000
EUR-Ggw.              31,73
Entgeltinformationen finden
Sie auf der sep. Rechnung.
RK 13.07.23
EOF;
        $expected = [
            'booking_code' => '201',
            'booking_text' => 'AUSLANDSGESCHAEFT',
            'description' => [
                'SVWZ' => $expected_lines,
            ],
            'primanoten_nr' => '7018',
            'description_1' => $expected_lines,
            'bank_code' => '57000000',
            'account_number' => '9000000000',
            'name' => '1/HANS WURST',
            'text_key_addition' => '888',
            'desc_lines' => explode(PHP_EOL, $expected_lines)
        ];

        self::assertSame($expected, array_filter($this->mt940->parseDescription($raw)));
    }


	    public function testDescriptions()
    {
        $raw = <<<EOF
:86:166?00GUTSCHR. UEBERWEISUNG?109310?20EREF+ST0122?21SVWZ+R2500
107 Webseiten- un?22d Shopbetreuung Leistungsze?23itraum. 2025/7?
30MALADE00STD?31DE60000000000000000000?32exampl GmbH
:86:166?00GUTSCHR. UEBERWEISUNG?109310?20EREF+ST0123?21SVWZ+R2500
113 Software Pro?22als Cloudanwendung. Mandant?23. https.//exampl
.software.d?24e/ Leistungszeitraum. 2025/?257?30MALADE00STD?31DE6
0000000000000000000?32exampl GmbH
:86:166?00GUTSCHR. UEBERWEISUNG?109310?20EREF+ST0628?21SVWZ+R2500
111 Software Pro?22als Cloudanwendung. Mandant?23. https.//xxxxxx
xx.foo.ag/?24Leistungszeitraum. 2025/7?30MALADE00STD?31DE23000000
000000000000?32FOO AG
:86:166?00GUTSCHR. UEBERWEISUNG?109310?20EREF+ST0628?21SVWZ+R2500
111 Software Pro?22als Cloudanwendung. Mandant?23. https.//xxxxxx
xx.foo.ag/?24Leistungszeitraum. 2025/7xx?25xxxxxxxxxxxxxxxxxxxxx?
26das hier darf nicht fehlen?30MALADE00STD?31DE230000000000000000
00?32FOO AG
EOF;
        // only "\r\n" are permitted in SEPA xml
        $descriptions = explode(':86:', str_replace("\n", "\r\n", $raw));
        $this->assertEquals('166?00GUTSCHR. UEBERWEISUNG?109310?20EREF+ST0122?21SVWZ+R2500107 Webseiten- un?22d Shopbetreuung Leistungsze?23itraum. 2025/7?30MALADE00STD?31DE60000000000000000000?32exampl GmbH', $this->mt940->dechunkDescription($descriptions[1]));
        $this->assertEquals('R2500107 Webseiten- und Shopbetreuung Leistungszeitraum. 2025/7', $this->mt940->parseDescription($descriptions[1])['description']['SVWZ']);
        // Leider gehen hier Leerzeichen verloren. Ändert man das Verhalten und joined mit ' ', ist der vorherige Fall kaputt.
        // Das Verhalten hier ist aber näher an der Spezifikation:
        $this->assertEquals('R2500113 Software Proals Cloudanwendung. Mandant. https.//exampl.software.de/ Leistungszeitraum. 2025/7', $this->mt940->parseDescription($descriptions[2])['description']['SVWZ']);
        $this->assertEquals('R2500111 Software Proals Cloudanwendung. Mandant. https.//xxxxxxxx.foo.ag/Leistungszeitraum. 2025/7', $this->mt940->parseDescription($descriptions[3])['description']['SVWZ']);
        $this->assertEquals('R2500111 Software Proals Cloudanwendung. Mandant. https.//xxxxxxxx.foo.ag/Leistungszeitraum. 2025/7xxxxxxxxxxxxxxxxxxxxxxxdas hier darf nicht fehlen', $this->mt940->parseDescription($descriptions[4])['description']['SVWZ']);

        // Extracted and modified from consors integration test
        // consors starts chunking after :86:, sparkasse includes :86:
        $description_consors = "005?00Lastschrift (Einzugsermächtigung)?20EREF+ZAA0987654321     \r\n    ?21             ?22KREF+NONREF?23SVWZ+Log\r\nPaOnlineTicket i.?\r\n24A.v. Irgendeine Firma und S?25oehne AG. Ihre Kundenn r. 2?26019\r\n999999999?30BICBICBI?31DExx555555555555555555?32LOGPAY FINANCIAL \r\nSERVICES G?33MBH\r\n";
        // note \r\n in `Log\r\nPa` being kept because it's part of the description:
        $this->assertEquals(
            "005?00Lastschrift (Einzugsermächtigung)?20EREF+ZAA0987654321         ?21             ?22KREF+NONREF?23SVWZ+Log\r\nPaOnlineTicket i.?24A.v. Irgendeine Firma und S?25oehne AG. Ihre Kundenn r. 2?26019999999999?30BICBICBI?31DExx555555555555555555?32LOGPAY FINANCIAL SERVICES G?33MBH",
            $this->mt940->dechunkDescription($description_consors)
        );
        $this->assertEquals(
            "Log\nPaOnlineTicket i.A.v. Irgendeine Firma und Soehne AG. Ihre Kundenn r. 2019999999999",
            $this->mt940->parseDescription($description_consors)['description']['SVWZ'],
        );
    }
}