<?php

namespace Finn\FinnClient;
use Finn\RestClient\CurlClient;
use Finn\RestClient\ClientInterface;

class FinnClient
{
    private $restClient = null;
    private $apiUrl = 'https://cache.api.finn.no/iad/';

    /**
     * Create a new Client object
     *
     * @param $restClient: A restclient implementing ClientInterface
     */
    function __construct(ClientInterface $restClient)
    {
        $this->restClient = $restClient;
    }

    /**
     * Do a search for properties
     *
     * @param $type: finn realestate type 'realestate-homes'
     * @param $queryParams: Array with query parameters
     * @return Singleton
     */
    public function search($type, $queryParams)
    {
        $url = $this->apiUrl . 'search/' . $type . '?' . http_build_query($queryParams);
        $rawData = $this->restClient->send($url);

        // Parse the data to the object
        if (isset($rawData)) {
            $resultSet = $this->parseResultset($rawData);
            return $resultSet;
        }
    }

    /**
     * Get single object with finncode
     *
     * @param $type: finn realestate type 'realestate-homes'
     * @param $finncode: The ads finncode
     */
    public function getObject($type, $finncode)
    {
        $url = $this->apiUrl . 'ad/' . $type . '/' . $finncode;
        $rawData = $this->restClient->send($url);

        // Parse the data to the object
        if (isset($rawData)) {
            $entry = simplexml_load_string($rawData);
            $ns = $entry->getNameSpaces(true);

            $resultSet = $this->parseEntry($entry, $ns);
            return $resultSet;
        }
    }

    /**
     *
     *
     */
    private function parseEntry($entry, $ns)
    {
        $property = new Singleton();

        $property->id = (string) $entry->children($ns['dc'])->identifier;
        $property->title = (string) $entry->title;
        $property->updated = (string) $entry->updated;
        $property->published = (string) $entry->published;

        $links = array();
        foreach ($entry->link as $link) {
            $rel = (string) $link->attributes()->rel;
            $ref = (string) $link->attributes()->href;

            array_push($links, array(
                'rel' => $rel,
                'ref' => $ref,
            ));
        }
        $property->links = $links;

        $isPrivate = 'false';
        $status = '';
        $adType = '';

        foreach ($entry->category as $category) {
            $attributes = $category->attributes();

            if ($attributes->scheme == 'urn:finn:ad:private') {
                $isPrivate = $attributes->term;
            }

            if ($attributes->scheme == 'urn:finn:ad:disposed') {
                if ($attributes->term == 'true') {
                    $status = $attributes->label;
                }
            }

            if ($attributes->scheme == 'urn:finn:ad:type') {
                $adType = $attributes->term;
            }
        }

        $property->isPrivate = (string) $isPrivate;
        $property->status = (string) $status;
        $property->adType = (string) $adType;

        $location = $entry->children($ns['finn'])->location;
        $property->city = (string) $location->children($ns['finn'])->city;
        $property->address = (string) $location->children($ns['finn'])->address;
        $property->postalCode = (string) $location->children($ns['finn'])->{'postal-code'};

        if (!empty($ns['georss'])) {
            $geo = explode(' ', (string) $entry->children($ns['georss'])->point);

            if (!empty($geo)) {
                $property->geo = array(
                    'lat' => $geo[0],
                    'lng' => $geo[1],
                );
            }
        }

        $contacts = array();

        foreach($entry->children($ns['finn'])->contact as $contact) {
            $name = (string) $contact->children()->name;
            $email = (string) $contact->children()->email;
            $title = (string) $contact->attributes()->title;

            foreach($contact->{'phone-number'} as $numbers) {
                switch($numbers->attributes()) {
                    case 'work':
                        $work = (string) $numbers;
                        break;
                    case 'mobile':
                        $mobile = (string) $numbers;
                        break;
                    case 'fax':
                        $fax = (string) $numbers;
                        break;
                }
            }
            array_push($contacts, array(
                'name'      => isset($name) ? $name : null,
                'email'     => isset($email) ? $email : null,
                'title'     => isset($title) ? $title : null,
                'work'      => isset($work) ? $work : null,
                'mobile'    => isset($mobile) ? $mobile : null,
                'fax'       => isset($fax) ? $fax : null
            ));
        }

        if (!empty($contacts)) {
            $property->contacts = $contacts;
        }

        $images = array();
        if ($entry->children($ns['media']) && $entry->children($ns['media'])->content->attributes()) {
            foreach($entry->children($ns['media'])->content as $content) {
                $images[] = current($content->attributes());
            }
        }
        $property->images = $images;

        $property->author = (string) $entry->author->name;

        $adata = $entry->children($ns['finn'])->adata;
        foreach ($adata->children($ns['finn'])->field as $field) {
            $attributes = $field->attributes();

            if ($attributes->name == 'housing_unit') {
                $property->housingUnit = (string) explode(', ', $attributes->value)[0];
            }

            if ($attributes->name == 'floor') {
                $property->floor = (string) explode(', ', $attributes->value)[0];
            }

            if ($attributes->name == 'no_of_floors') {
                $property->numberOfFloors = (string) $attributes->value;
            }

            if ($attributes->name == 'no_of_units') {
                $property->numberOfUnits = (string) $attributes->value;
            }

            if ($attributes->name == 'no_of_bedrooms') {
                if ($attributes->value) {
                    $property->numberOfBedrooms = (string) $attributes->value;
                } elseif ($attributes->from && $attributes->to) {
                    $property->numberOfBedrooms = (string) ($attributes->from . ' &mdash; ' . $attributes->to);
                }
            }

            if ($attributes->name == 'no_of_rooms') {
                $property->numberOfRooms = (string) $attributes->value;
            }

            if ($attributes->name == 'property_type') {
                $property->propertyType = (string) $field->children($ns['finn'])->value;
            }

            if ($attributes->name == 'ownership_type') {
                $property->ownershipType = (string) $attributes->value;
            }

            if ($attributes->name == 'viewing_date') {
                $property->viewingDates = [];

                foreach ($field->children($ns['finn'])->value as $date) {
                    $property->viewingDates[] = (string) $date;
                }
            }

            if ($attributes->name == 'size') {
                foreach ($field->children($ns['finn'])->field as $sizeField) {
                    if ($sizeField->attributes()->name == 'usable') {
                        $property->usableSize = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->name == 'primary') {
                        $property->primarySize = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->from) {
                        $property->primarySizeFrom = (string) $sizeField->attributes()->from;
                    }

                    if ($sizeField->attributes()->to) {
                        $property->primarySizeTo = (string) $sizeField->attributes()->to;
                    }
                }
            }

            if ($attributes->name == 'area') {
                foreach ($field->children($ns['finn'])->field as $sizeField) {
                    if ($sizeField->attributes()->name == 'from') {
                        $property->primarySizeFrom = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->name == 'to') {
                        $property->primarySizeTo = (string) $sizeField->attributes()->value;
                    }
                }
            }

            if ($attributes->name == 'facilities') {
                $facilities = array();

                foreach($field->children($ns['finn'])->value as $facility) {
                    $facilities[] = (string) $facility;
                }

                $property->facilities = $facilities;
            }

            if ($attributes->name == 'general_text') {
                $descriptions = array();
                $i = 0;

                foreach($field->children($ns['finn'])->value as $text) {
                    foreach($text->children($ns['finn'])->field as $t) {
                        if ($t->attributes()->name == 'title') {
                            $descriptions[$i]['title'] = (string) $t->attributes()->value;
                        }
                        if ($t->attributes()->name == 'value') {
                            $descriptions[$i]['value'] = (string) $t;
                        }
                    }
                    $i++;
                }

                $property->descriptions = $descriptions;
            }

            if ($attributes->name == 'ingress') {
                $property->ingress = (string) $field;
            }

            if ($attributes->name == 'situation') {
                $property->situation = (string) $field;
            }

            if ($attributes->name == 'viewings') {
                $viewings = array();
                $i = 0;

                foreach($field->children($ns['finn'])->value as $text) {
                    foreach($text->children($ns['finn'])->field as $t) {
                        if ($t->attributes()->name == 'note') {
                            $viewings[$i]['note'] = (string) $t->attributes()->value;
                        }

                        if ($t->attributes()->name == 'date') {
                            $viewings[$i]['date'] = (string) $t;
                        }

                        if ($t->attributes()->name == 'from') {
                            $viewings[$i]['from'] = (string) $t->attributes()->value;
                        }

                        if ($t->attributes()->name == 'to') {
                            $viewings[$i]['to'] = (string) $t->attributes()->value;
                        }
                    }
                    $i++;
                }

                $property->viewings = $viewings;
            }
        }

        foreach ($adata->children($ns['finn'])->price as $price) {
            $attributes = $price->attributes();

            if ($attributes->name == 'total') {
                if ($attributes->value) {
                    $property->totalPrice = (string) $attributes->value;
                }

                if ($attributes->from) {
                    $property->totalPriceFrom = (string) $attributes->from;
                }

                if ($attributes->to) {
                    $property->totalPriceTo = (string) $attributes->to;
                }
            }

            if ($attributes->name == 'main') {
                if ($attributes->value) {
                    $property->mainPrice = (string) $attributes->value;

                    if (empty($property->totalPrice)) {
                        $property->totalPrice = $property->mainPrice;
                    }
                }

                if ($attributes->from) {
                    $property->mainPriceFrom = (string) $attributes->from;

                    if (empty($property->totalPriceFrom)) {
                        $property->totalPriceFrom = $property->mainPriceFrom;
                    }
                }

                if ($attributes->to) {
                    $property->mainPriceTo = (string) $attributes->to;

                    if (empty($property->totalPriceTo)) {
                        $property->totalPriceTo = $property->mainPriceTo;
                    }
                }
            }

            if ($attributes->name == 'collective_debt' && $attributes->value) {
                $property->collectiveDebt = (string) $attributes->value;
            }

            if ($attributes->name == 'shared_cost' && $attributes->value) {
                $property->sharedCost = (string) $attributes->value;
            }

            if ($attributes->name == 'estimated_value' && $attributes->value) {
                $property->estimatedValue = (string) $attributes->value;
            }

            if ($attributes->name == 'square_meter' && $attributes->value) {
                $property->sqmPrice = (string) $attributes->value;
            }
        }

        return $property;
    }

    //Returns an array of objects
    private function parseResultset($rawData)
    {
        $resultset = new Singleton();

        //parse the xml and get namespaces (needed later to extract attributes and values)
        $xml = simplexml_load_string($rawData);
        $ns = $xml->getNameSpaces(true);

        //search data:
        $resultset->title = (string) $xml->title;
        $resultset->subtitle = (string) $xml->subtitle;
        $resultset->totalResults = (string) $xml->children($ns['os'])->totalResults;

        // Sort options
        $sorts = array();
        foreach ($xml->xpath('f:sort/os:Query') as $sort) {
            $attrs = $sort->attributes();
            $fattrs = $sort->attributes('f', true);

            array_push($sorts, array(
                'selected' => (bool) $fattrs['selected'],
                'value' => (string) $fattrs['sort'],
                'title' => (string) $attrs['title'],
            ));
        }
        $resultset->sort = $sorts;

        //navigation links
        $links = array();
        foreach ($xml->link as $link) {
            $rel = (string) $link->attributes()->rel;
            $ref = (string) $link->attributes()->href;

            array_push($links, array(
                'rel' => $rel,
                'ref' => $ref,
            ));
        }
        $resultset->links = $links;

        //get each entry for simpler syntax when looping through them later
        $entries = array();
        foreach ($xml->entry as $entry) {
            array_push($entries, $entry);
        }

        $propertyList = array();
        foreach ($entries as $entry) {
            $property = $this->parseEntry($entry, $ns);
            $propertyList[] = $property;
        }

        $resultset->results = $propertyList;

        return $resultset;
    }


}
