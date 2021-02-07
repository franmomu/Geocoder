<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\MapTiler;

use Geocoder\Collection;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Location;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Provider;
use Http\Client\HttpClient;

/**
 * @author Jonathan Beliën
 */
final class MapTiler extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const ENDPOINT_URL = 'https://api.maptiler.com/geocoding/%s.json?key=%s';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param HttpClient $client an HTTP client
     * @param string     $key    API key
     */
    public function __construct(HttpClient $client, string $apiKey)
    {
        parent::__construct($client);

        $this->apiKey = $apiKey;
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        // This API doesn't handle IPs
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The MapTiler provider does not support IP addresses.');
        }

        $url = sprintf(self::ENDPOINT_URL, $address, $this->apiKey);

        $json = $this->executeQuery($url, $query->getLocale(), $query->getBounds());

        if (!isset($json->features) || empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $results[] = $this->featureToAddress($feature);
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        $coordinates = $query->getCoordinates();

        $url = sprintf(self::ENDPOINT_URL, implode(',', $coordinates->toArray()), $this->apiKey);

        $json = $this->executeQuery($url, $query->getLocale());

        if (!isset($json->features) || empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $results[] = $this->featureToAddress($feature);
        }

        return new AddressCollection($results);
    }

    /**
     * @param \stdClass $feature
     *
     * @return Location
     */
    private function featureToAddress(\stdClass $feature): Location
    {
        $builder = new AddressBuilder($this->getName());

        $coordinates = 'Point' === $feature->geometry->type ? $feature->geometry->coordinates : $feature->center;

        $builder->setCoordinates(floatval($coordinates[1]), floatval($coordinates[0]));

        if (in_array('street', $feature->place_type, true)) {
            $builder->setStreetName($feature->text);
        } elseif (in_array('subcity', $feature->place_type, true)) {
            $builder->setSubLocality($feature->text);
        } elseif (in_array('city', $feature->place_type, true)) {
            $builder->setLocality($feature->text);
        }

        if (isset($feature->bbox)) {
            $builder->setBounds($feature->bbox[0], $feature->bbox[2], $feature->bbox[1], $feature->bbox[3]);
        }

        return $builder->build();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'maptiler';
    }

    /**
     * @param string $url
     *
     * @return \stdClass
     */
    private function executeQuery(string $url, string $locale = null, Bounds $bounds = null): \stdClass
    {
        $url .= '&'.http_build_query([
            'language' => $locale,
            'bbox' => !is_null($bounds) ? implode(',', $bounds->toArray()) : null,
        ]);

        $content = $this->getUrlContents($url);

        $json = json_decode($content);

        // API error
        if (is_null($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }
}
