<?php

namespace App\Service;

use App\Service\ReportGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates hourly labor cost percentage data and stores in lcp mongo collection.
 * The hourly data is used by the api to group day, week, and monthly interval results
 */
class LcpReportGenerator implements ReportGeneratorInterface
{
    private $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function generate(OutputInterface $output)
    {
        $businessCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('businesses');
        $lcpCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('lcp');

        $lcpCollection->remove([]);

        foreach ($businessCollection->find() as $business) {

            // im starting here because the test data set doesn't go back any further
            // in production I would generate these results every new hour or day
            $start = new \DateTime('2018-05-01T00:00:00.000Z');
            $end = new \DateTime();
            $count = 0;

            $output->writeln('Generating LCP stats for ' . $business['name']);

            $businessId = $business['id'];

            while ($start <= $end) {

                if ($count % 24 == 0) {

                    $output->writeln('From ' . $start->format('Y-m-d\TH:i:s.000\Z'));

                }
                $count++;

                $endOfHour = clone $start;
                $endOfHour->modify('+1 hour');

                $laborCost = $this->calculateLaborCost($businessId, $start->format('Y-m-d\TH:i:s.000\Z'), $endOfHour->format('Y-m-d\TH:i:s.000\Z'));
                $totalSales = $this->calculateSales($businessId, $start->format('Y-m-d\TH:i:s.000\Z'), $endOfHour->format('Y-m-d\TH:i:s.000\Z'));

                // returning 0 if we they paid for labor but made no sales
                $lcp = $totalSales > 0 ? ($laborCost / $totalSales) * 100 : 0;

                $document = [
                    'business_id' => $businessId,
                    'start' => $start->format('Y-m-d\TH:i:s.000\Z'),
                    'end' => $endOfHour->format('Y-m-d\TH:i:s.000\Z'),
                    'day' => $start->format('Y-m-d'),
                    'laborCost' => $laborCost,
                    'totalSales' => $totalSales,
                    'lcp' => round($lcp, 2),
                ];

                $lcpCollection->insert($document);

                $start = $endOfHour;

            }
        }

    }

    private function calculateSales($businessId, $start, $end)
    {
        $aggregateQuery = [
            ['$match' => [
                'business_id' => $businessId,
                'created_at' => [
                    '$gte' => $start,
                    '$lte' => $end,
                ],
                'voided' => false,
            ]],
            ['$group' => [
                '_id' => null,
                'totalSales' => [
                    '$sum' => '$price'
                ]
            ]]
        ];

        $collection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('orderedItems');

        $cursor = $collection->aggregate($aggregateQuery, ['cursor' => true]);

        $totalSales = 0;

        foreach ($cursor as $result) {
            $totalSales = $result['totalSales'];
        }

        return $totalSales;
    }

    private function calculateLaborCost($businessId, $start, $end)
    {
        // there are 4 ways in which clock in / out can overlap with start / end
        // first filter the results based on these cases
        $aggregateQuery = [
            ['$match' => [
                'business_id' => $businessId,
                '$or' => [
                    [
                        'clock_in' => [
                            '$gte' => $start,
                            '$lt' => $end
                        ],
                        'clock_out' => [
                            '$lte' => $end
                        ]
                    ],
                    [
                        'clock_in' => [
                            '$gte' => $start,
                            '$lt' => $end
                        ],
                        'clock_out' => [
                            '$gt' => $end
                        ]
                    ],
                    [
                        'clock_in' => [
                            '$lt' => $start,
                        ],
                        'clock_out' => [
                            '$gt' => $start,
                            '$lte' => $end
                        ]
                    ],
                    [
                        'clock_in' => [
                            '$lt' => $start,
                        ],
                        'clock_out' => [
                            '$gt' => $end
                        ]
                    ]
                ]
            ]]
        ];

        $collection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('laborEntries');

        $cursor = $collection->aggregate($aggregateQuery, ['cursor' => true]);

        $totalLaborCost = 0;
        $startDateTime = new \DateTime($start);
        $endDateTime = new \DateTime($end);

        foreach ($cursor as $result) {

            $clockIn = new \DateTime($result['clock_in']);
            $clockOut = new \DateTime($result['clock_out']);
            $payRate = $result['pay_rate'];

            // there are 4 ways in which clock in / out can overlap with start / end

            // in/out        |     |
            // start/end    |       |
            if ($clockIn >= $startDateTime && $clockIn < $endDateTime && $clockOut <= $endDateTime) {
                $hoursWorked = $clockOut->diff($clockIn)->h;
                $totalLaborCost += $payRate * $hoursWorked;
            }

            // in/out      |     |
            // start/end  |     |
            if ($clockIn > $startDateTime && $clockIn < $endDateTime && $clockOut > $endDateTime) {
                $hoursWorked = $endDateTime->diff($clockIn)->h;
                $totalLaborCost += $payRate * $hoursWorked;
            }

            // in/out     |     |
            // start/end   |     |
            if ($clockIn < $startDateTime && $clockOut > $startDateTime && $clockOut <= $endDateTime) {
                $hoursWorked = $clockOut->diff($startDateTime)->h;
                $totalLaborCost += $payRate * $hoursWorked;
            }

            // in/out     |      |
            // start/end   |    |
            if ($clockIn < $startDateTime && $clockOut > $endDateTime) {
                $hoursWorked = $endDateTime->diff($startDateTime)->h;
                $totalLaborCost += $payRate * $hoursWorked;
            }

        }

        return $totalLaborCost;
    }
}
