<?php

namespace BeeDelivery\GoogleMaps\Utils;

trait HelpersGeoCoding
{
    public function formatRequest($search, $type, $restrictions = null, $restrictionType = null)
    {
        $searchUrl = match ($type) {
            'place_id' => 'place_id=' . $search,
            'address'  => 'address=' . $search,
            'latlng'   => 'latlng=' . $search,
        };

        $restrictionsUrl = match ($restrictionType) {
            null => '',
            'city' => '&components=administrative_area:' . $restrictions . '|country:Brasil',
            'postal_code' => '&components=postal_code:' . $restrictions
        };

        return config('googlemaps.geocoding_url') . $searchUrl . $restrictionsUrl;
    }

    public function formatResponse($response)
    {
        $addresses = ['addresses' => []];
        if ($response['status'] == 'ZERO_RESULTS')
            return $addresses;

        foreach ($response['results'] as $result) {
            $addresses['addresses'][] = $this->mapAddressComponents($result);
        }

        return $addresses;
    }

    private function mapAddressComponents($result)
    {
        $componentsMap = [
            'route' => 'addressStreet',
            'street_number' => 'addressNumber',
            'sublocality_level_1' => 'addressNeiborhood',
            'administrative_area_level_2' => 'addressCity',
            'administrative_area_level_1' => 'addressState',
            'country' => 'addressCountry',
            'postal_code' => 'addressPostalCode',
        ];

        $addressStreet = $addressNumber = $addressNeiborhood = $addressCity = $addressState = $addressCountry = $addressPostalCode = '';

        foreach ($result['address_components'] as $address) {
            foreach ($address['types'] as $type) {
                $variableName = match ($type) {
                    'route', 'street_number', 'sublocality_level_1', 'administrative_area_level_2', 'administrative_area_level_1', 'country', 'postal_code' => $componentsMap[$type],
                    default => null,
                };

                if ($variableName) {
                    if ($type === 'administrative_area_level_1') {
                        $$variableName = $address['short_name'];
                    } else {
                        $$variableName = $address['long_name'];
                    }
                }
            }
        }
        $addressStreet = empty($addressStreet) && empty($addressNumber) ? $result['formatted_address'] : $addressStreet;
        return [
            'address' => [
                'street' => $addressStreet ?? '',
                'number' => $addressNumber ?? '',
                'neighborhood' => $addressNeiborhood ?? '',
                'zipcode' => str_replace('-', '', $addressPostalCode ?? ''),
                'complement' => '',
                'city' => $addressCity ?? '',
                'state' => $addressState ?? '',
                'country' => $addressCountry ?? '',
                'formatted' => $result['formatted_address'],
            ],
            'lat' => $result['geometry']['location']['lat'],
            'lng' => $result['geometry']['location']['lng'],
            'partial' => isset($result["partial_match"])
        ];
    }
}
