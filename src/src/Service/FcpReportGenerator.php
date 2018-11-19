<?php

namespace App\Service;

use App\Service\ReportGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates hourly food cost percentage data and stores in fcp mongo collection.
 * The hourly data is used by the api to group day, week, and monthly interval results
 */
class FcpReportGenerator implements ReportGeneratorInterface
{
    private $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function generate(OutputInterface $output)
    {
        $businessCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('businesses');
        $fcpCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('fcp');

        $fcpCollection->remove([]);

        foreach ($businessCollection->find() as $business) {

            // im starting here because the test data set doesn't go back any further
            // in production I would generate these results every new hour or day
            $start = new \DateTime('2018-05-01T00:00:00.000Z');
            $end = new \DateTime();
            $count = 0;

            $output->writeln('Generating FCP stats for ' . $business['name']);

            $businessId = $business['id'];

            while ($start <= $end) {

                if ($count % 24 == 0) {

                    $output->writeln('From ' . $start->format('Y-m-d\TH:i:s.000\Z'));

                }
                $count++;

                $endOfHour = clone $start;
                $endOfHour->modify('+1 hour');

                list($price, $cost) = $this->calculateFoodCostAndPrice($businessId, $start->format('Y-m-d\TH:i:s.000\Z'), $endOfHour->format('Y-m-d\TH:i:s.000\Z'));

                $fcp = $price > 0 ? ($cost / $price) * 100 : 0;

                $document = [
                    'business_id' => $businessId,
                    'start' => $start->format('Y-m-d\TH:i:s.000\Z'),
                    'end' => $endOfHour->format('Y-m-d\TH:i:s.000\Z'),
                    'day' => $start->format('Y-m-d'),
                    'price' => $price,
                    'cost' => $cost,
                    'fcp' => round($fcp, 2),
                ];

                $fcpCollection->insert($document);

                $start = $endOfHour;

            }
        }

    }

    private function calculateFoodCostAndPrice($businessId, $start, $end)
    {
        $aggregateQuery = [
            ['$match' => [
                'business_id' => $businessId,
                'voided' => false,
                'created_at' => [
                    '$gte' => $start,
                    '$lt' => $end,
                ]
            ]],
            ['$group' => [
                '_id' => null,
                'totalPrice' => ['$sum' => '$price'],
                'totalCost' => ['$sum' => '$cost'],
            ]]
        ];

        $collection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('orderedItems');

        $cursor = $collection->aggregate($aggregateQuery, ['cursor' => true]);

        $result = $cursor->getSingleResult();

        $totalPrice = $result ? $result['totalPrice'] : 0;
        $totalCost = $result ? $result['totalCost']: 0;

        return [$totalPrice, $totalCost];
    }
}
