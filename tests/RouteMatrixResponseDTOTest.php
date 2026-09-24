<?php

namespace Tests;

use BeeDelivery\GoogleMaps\DTOs\RouteMatrixResponseDTO;
use BeeDelivery\GoogleMaps\Utils\HelpersRouteMatrix;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class RouteMatrixResponseDTOTest extends PHPUnitTestCase
{
    public function testReadsAnElementTheApiCouldRoute(): void
    {
        $element = RouteMatrixResponseDTO::fromResponse([
            'originIndex' => 0,
            'destinationIndex' => 1,
            'distanceMeters' => 1500,
            'duration' => '180s',
            'condition' => 'ROUTE_EXISTS',
        ]);

        $this->assertTrue($element->routeExists);
        $this->assertSame(1500, $element->distanceMeters);
        $this->assertSame(180, $element->durationInSeconds);
    }

    public function testReadsAnElementTheApiCouldNotRoute(): void
    {
        // Quando não existe rota a API omite distanceMeters e duration, e é só pelo
        // condition que dá para distinguir isso de uma perna de distância zero.
        $element = RouteMatrixResponseDTO::fromResponse([
            'originIndex' => 0,
            'destinationIndex' => 1,
            'condition' => 'ROUTE_NOT_FOUND',
        ]);

        $this->assertFalse($element->routeExists);
        $this->assertSame(0, $element->distanceMeters);
        $this->assertSame(0, $element->durationInSeconds);
    }

    public function testAssumesTheRouteExistsWhenTheApiDoesNotSayOtherwise(): void
    {
        $element = RouteMatrixResponseDTO::fromResponse([
            'originIndex' => 0,
            'destinationIndex' => 1,
            'distanceMeters' => 0,
            'duration' => '0s',
        ]);

        $this->assertTrue($element->routeExists);
    }

    public function testAsksTheApiForTheConditionOfEachElement(): void
    {
        $fieldMask = (new class
        {
            use HelpersRouteMatrix;
        })->formatFieldMask();

        $this->assertStringContainsString('condition', $fieldMask);
    }
}
