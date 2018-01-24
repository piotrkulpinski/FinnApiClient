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
            $rel = $link->attributes()->rel;
            $ref = $link->attributes()->href;
            $links['$rel'] = '$ref';
        }
        $property->links = $links;

        $isPrivate = 'false';
        $status = ';
        $adType = ';
        foreach ($entry->category as $category) {
            if ($category->attributes()->scheme =='urn:finn:ad:private') {
                $isPrivate = $category->attributes()->term;
            }
            //if disposed == true, show the label
            if ($category->attributes()->scheme =='urn:finn:ad:disposed') {
                if ($entry->category->attributes()->term == 'true') {
                    $status = $category->attributes()->label;
                }
            }
            if ($category->attributes()->scheme =='urn:finn:ad:type') {
                $adType = $category->attributes()->label;
            }
        }

        $property->isPrivate = (string) $isPrivate;
        $property->status = (string) $status;
        $property->adType = (string) $adType;

        $location = $entry->children($ns['finn'])->location;
        $property->city = (string) $location->children($ns['finn'])->city;
        $property->address = (string) $location->children($ns['finn'])->address;
        $property->postalCode = (string) $location->children($ns['finn'])->{'postal-code'};
        $geo = explode(' ', (string) $entry->children($ns['georss'])->point);

        if (!empty($geo)) {
            $property->geo = array(
                'lat' => $geo[0],
                'lng' => $geo[1],
            );
        }

        $contacts = array();

        foreach($entry->children($ns['finn'])->contact as $contact) {
            $name = (string) $contact->children()->name;
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
                'name' => $name,
                'title' => $title,
                'work' => $work,
                'mobile' => $mobile,
                'fax' => $fax
            ));
        }

        if (!empty($contacts)) {
            $property->contacts = $contacts;
        }

        $images = array();
        if ($entry->children($ns['media']) && $entry->children($ns['media'])->content->attributes()) {
            //$images = $entry->children($ns['media'])->content->attributes();
            foreach($entry->children($ns['media'])->content as $content) {
                $images[] = current($content->attributes());
            }
        }
        $property->images = $images;

        $property->author = (string) $entry->author->name;

        $adata = $entry->children($ns['finn'])->adata;
        foreach ($adata->children($ns['finn'])->field as $field) {
            if ($field->attributes()->name == 'no_of_bedrooms') {
                $property->numberOfBedrooms = (string) $field->attributes()->value;
            }

            if ($field->attributes()->name == 'property_type') {
                $property->propertyType = (string) $field->children($ns['finn'])->value;
            }

            if ($field->attributes()->name == 'ownership_type') {
                $property->ownershipType = (string) $field->attributes()->value;
            }

            if ($field->attributes()->name == 'size') {
                foreach ($field->children($ns['finn'])->field as $sizeField) {
                    if ($sizeField->attributes()->name == 'usable') {
                        $property->usableSize = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->name == 'primary') {
                        $property->primarySize = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->from) {
                        $property->livingSizeFrom = (string) $sizeField->attributes()->from;
                    }

                    if ($sizeField->attributes()->to) {
                        $property->livingSizeTo = (string) $sizeField->attributes()->to;
                    }
                }
            }

            if ($field->attributes()->name == 'area') {
                foreach ($field->children($ns['finn'])->field as $sizeField) {
                    if ($sizeField->attributes()->name == 'from') {
                        $property->livingSizeFrom = (string) $sizeField->attributes()->value;
                    }

                    if ($sizeField->attributes()->name == 'to') {
                        $property->livingSizeTo = (string) $sizeField->attributes()->value;
                    }
                }
            }

            if ($field->attributes()->name == 'facilities') {
                $facilities = array();

                foreach($field->children($ns['finn'])->value as $facility) {
                    $facilities[] = (string) $facility;
                }

                $property->facilities = $facilities;
            }

            if ($field->attributes()->name == 'general_text') {
                $generalText = array();
                $i = 0;

                foreach($field->children($ns['finn'])->value as $text) {
                    foreach($text->children($ns['finn'])->field as $t) {
                        if ($t->attributes()->name == 'title') {
                            $generalText[$i]['title'] = (string) $t->attributes()->value;
                        }
                        if ($t->attributes()->name == 'value') {
                            $generalText[$i]['value'] = (string) $t;
                        }
                    }
                    $i++;
                }

                $property->generalText = $generalText;
            }

            if ($field->attributes()->name == 'ingress') {
                $property->ingress = (string) $field;
            }

            if ($field->attributes()->name == 'situation') {
                $property->situation = (string) $field;
            }
        }

        foreach ($adata->children($ns['finn'])->price as $price) {
            if ($price->attributes()->name == 'main') {
                $property->mainPrice = (string) $price->attributes()->value;
            }
            if ($price->attributes()->name == 'total') {
                $property->totalPrice = (string) $price->attributes()->value;
            }
            if ($price->attributes()->name == 'collective_debt') {
                $property->collectiveDebt = (string) $price->attributes()->value;
            }
            if ($price->attributes()->name == 'shared_cost') {
                $property->sharedCost = (string) $price->attributes()->value;
            }
            if ($price->attributes()->name == 'estimated_value') {
                $property->estimatedValue = (string) $price->attributes()->value;
            }
            if ($price->attributes()->name == 'square_meter') {
                $property->sqmPrice = (string) $price->attributes()->value;
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
                'value' => $fattrs['sort'],
                'title' => $attrs['title'],
            ));
        }
        $resultset->sort = $sorts;

        //navigation links
        $links = array();
        foreach ($xml->link as $link) {
            $rel = $link->attributes()->rel;
            $ref = $link->attributes()->href;

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
