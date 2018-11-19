<?php

namespace App\Service;

use App\Service\ReportGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates hourly employee gross sales data and stores in egs mongo collection.
 * The hourly data is used by the api to group day, week, and monthly interval results
 */
class EgsReportGenerator implements ReportGeneratorInterface
{
    private $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function generate(OutputInterface $output)
    {
        $businessCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('businesses');
        $employeeCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('employees');
        $egsCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('egs');

        $egsCollection->remove([]);

        foreach ($businessCollection->find() as $business) {

            foreach ($employeeCollection->find(['business_id' => $business['id']]) as $employee) {

                // im starting here because the test data set doesn't go back any further
                // in production I would generate these results every new hour or day
                $start = new \DateTime('2018-05-01T00:00:00.000Z');
                $end = new \DateTime();
                $count = 0;

                $output->writeln('Generating EGS stats for business ' . $business['name'] . ', employee ' . $employee['first_name'] . ' ' . $employee['last_name']);

                $businessId = $business['id'];
                $employeeId = $employee['id'];

                while ($start <= $end) {

                    if ($count % 24 == 0) {

                        $output->writeln('From ' . $start->format('Y-m-d\TH:i:s.000\Z'));

                    }
                    $count++;

                    $endOfHour = clone $start;
                    $endOfHour->modify('+1 hour');

                    $sales = $this->calculateEmployeeGrossSales($businessId, $employeeId, $start->format('Y-m-d\TH:i:s.000\Z'), $endOfHour->format('Y-m-d\TH:i:s.000\Z'));

                    $document = [
                        'business_id' => $businessId,
                        'employee_id' => $employeeId,
                        'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
                        'start' => $start->format('Y-m-d\TH:i:s.000\Z'),
                        'end' => $endOfHour->format('Y-m-d\TH:i:s.000\Z'),
                        'day' => $start->format('Y-m-d'),
                        'sales' => $sales,
                    ];

                    $egsCollection->insert($document);

                    $start = $endOfHour;

                }

            }
        }

    }

    private function calculateEmployeeGrossSales($businessId, $employeeId, $start, $end)
    {
        $aggregateQuery = [
            ['$match' => [
                'business_id' => $businessId,
                'employee_id' => $employeeId,
                'voided' => false,
                'created_at' => [
                    '$gte' => $start,
                    '$lt' => $end,
                ]
            ]],
            ['$group' => [
                '_id' => null,
                'totalSales' => ['$sum' => '$price'],
            ]]
        ];

        $collection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('orderedItems');

        $cursor = $collection->aggregate($aggregateQuery, ['cursor' => true]);

        $result = $cursor->getSingleResult();

        $totalSales = $result['totalSales'] ? $result['totalSales'] : 0;

        return $totalSales;
    }
}
