<?php

namespace BeeDelivery\GoogleMaps\Utils;

use Exception;

trait HelpersAutoComplete
{
    public function url()
    {
        return config('googlemaps.autocomplete_url');
    }

    public function formatFieldMask(): string
    {
        return 'suggestions.placePrediction.text.text,suggestions.placePrediction.placeId,suggestions.placePrediction.structuredFormat.mainText.text,suggestions.placePrediction.structuredFormat.secondaryText.text,suggestions.placePrediction.types';
    }

    public function formatRequest($searchText, $originLat, $originLng, $radius)
    {
        $data['input'] = $searchText;
        $data['languageCode'] = 'pt';

        if ($originLat != '' && $originLng != '') {

            $data['locationRestriction'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $originLat,
                        'longitude' => $originLng
                    ],
                    'radius' => $radius
                ]
            ];
        }

        return $data;
    }

    public function formatResponse($response): array
    {
        if (isset($response['error'])) {
            return [
                'code' => $response['error']['code'],
                'message' => $response['error']['message']
            ];
        }

        $predictions = ['predictions' => []];
        if (isset($response['suggestions'])) {
            foreach ($response['suggestions'] as $item) {
                if (isset($item['placePrediction']) && isset($item['placePrediction']['placeId']) && isset($item['placePrediction']['text']['text'])) {
                    $predictions['predictions'][] = [
                        'description' => $item['placePrediction']['text']['text'],
                        'placeId' => $item['placePrediction']['placeId'],
                        'mainText' => $item['placePrediction']['structuredFormat']['mainText']['text'],
                        'secondaryText' => $item['placePrediction']['structuredFormat']['secondaryText']['text'],
                        'is_establishment' => in_array('establishment', $item['placePrediction']['types']),
                    ];
                }
            }
        }
        return $predictions;
    }
}
