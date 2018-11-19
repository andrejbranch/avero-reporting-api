<?php

namespace App\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles syncronizing the local db with avero's test data set retreived from api.
 * This data is used to generate the hourly reporting data
 */
class SyncManager
{
    private $averoUrl;

    private $averoAuth;

    private $dm;

    public function __construct($averoUrl, DocumentManager $dm)
    {
        $this->averoUrl = $averoUrl;
        $this->dm = $dm;
    }

    public function sync($averoAuth, OutputInterface $output)
    {
        $this->averoAuth = $averoAuth;

        $resources = array(
            'businesses',
            'menuItems',
            'checks',
            'orderedItems',
            'employees',
            'laborEntries',
        );

        foreach ($resources as $resource) {

            $output->writeln('Syncronizing ' . $resource);

            $this->syncResource($resource);

        }
    }

    private function syncResource($resource)
    {
        $client = new Client();
        $continue = true;
        $offset = 0;
        $collection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection($resource);

        // first clear the collection
        $collection->remove([]);

        while ($continue) {

            $request = $client->request('GET', $this->averoUrl . '/' . $resource, [
                'query' => [
                    'limit' => 500,
                    'offset' => $offset,
                ],
                'headers' => [
                    'Authorization' => $this->averoAuth,
                ],
                'verify' => false,
            ]);

            $resultData = json_decode($request->getBody()->getContents(), true)['data'];

            $continue = count($resultData);

            if ($continue) {
                $collection->batchInsert($resultData);
            }

            $offset += $continue;
        }
    }
}
