<?php

namespace Tests;

use BeeDelivery\GoogleMaps\Utils\HelpersAutoComplete;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Cobre a montagem do corpo enviado ao Places. Não estende o TestCase do
 * pacote porque `formatRequest` é PHP puro: não precisa de app nem de chave.
 */
class AutocompleteFormatRequestTest extends PHPUnitTestCase
{
    private object $autocomplete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autocomplete = new class
        {
            use HelpersAutoComplete;
        };
    }

    public function testItRestrictsTheRegionWhenRegionCodesAreGiven(): void
    {
        $body = $this->autocomplete->formatRequest('Rua Blumenau', '', '', 50000, ['br']);

        $this->assertSame(['br'], $body['includedRegionCodes']);
        $this->assertArrayNotHasKey('locationRestriction', $body);
    }

    public function testItKeepsTheBodyUntouchedWhenNoRegionCodeIsGiven(): void
    {
        $body = $this->autocomplete->formatRequest('Rua Blumenau', '-26.3044', '-48.8487', 50000);

        $this->assertArrayNotHasKey('includedRegionCodes', $body);
        $this->assertSame([
            'circle' => [
                'center' => ['latitude' => '-26.3044', 'longitude' => '-48.8487'],
                'radius' => 50000,
            ],
        ], $body['locationRestriction']);
    }

    public function testItCombinesRegionCodesWithACoordinateRestriction(): void
    {
        $body = $this->autocomplete->formatRequest('Rua Blumenau', '-26.3044', '-48.8487', 10000, ['br', 'py']);

        $this->assertSame(['br', 'py'], $body['includedRegionCodes']);
        $this->assertSame(10000, $body['locationRestriction']['circle']['radius']);
    }

    public function testItReindexesRegionCodesSoTheySerializeAsAJsonArray(): void
    {
        $body = $this->autocomplete->formatRequest('Rua Blumenau', '', '', 0, [2 => 'br']);

        $this->assertSame('{"includedRegionCodes":["br"]}', json_encode([
            'includedRegionCodes' => $body['includedRegionCodes'],
        ]));
    }

    public function testItAlwaysAsksForPortuguese(): void
    {
        $body = $this->autocomplete->formatRequest('Rua Blumenau', '', '', 0, ['br']);

        $this->assertSame('pt', $body['languageCode']);
        $this->assertSame('Rua Blumenau', $body['input']);
    }
}
