<?php

namespace App\Service;

use App\Service\ReportManagerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles querying the lcp mongo collection using parameters passed in from the ReportingController.
 * Hourly data needs minimal manipulation as the LcpReportGenerator has stored this information.
 * Daily, weekly, and monthly data are aggregated using this hourly data.
 *
 * @see https://docs.mongodb.com/manual/aggregation for more information on mongos aggregation pipeline
 */
class LcpReportManager implements ReportManagerInterface
{
    private $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function find($params)
    {
        $lcpCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('lcp');

        $start = new \DateTime($params['start']);
        $end = new \DateTime($params['end']);

        $params['start'] = $start->format('Y-m-d\TH:i:s.000\Z');
        $params['end'] = $end->format('Y-m-d\TH:i:s.000\Z');

        list($aggregateCountQuery, $aggregateResultQuery) = $this->getIntervalQueries($params);

        $cursor = $lcpCollection->aggregate($aggregateCountQuery, ['cursor' => true]);
        $count = $cursor->getSingleResult()['total'];

        $cursor = $lcpCollection->aggregate($aggregateResultQuery, ['cursor' => true]);

        $results = [
            'report' => 'LCP',
            'timeInterval' => $params['timeInterval'],
            'count' => $count,
            'data' => [],
        ];

        foreach ($cursor as $result) {
            $results['data'][] = $result;
        }

        return $results;
    }

    public function getIntervalQueries($params)
    {
        if ($params['timeInterval'] == 'hour') {

            $aggregateBaseQuery = [
                ['$match' => [
                    'business_id' => $params['businessId'],
                    'start' => ['$gte' => $params['start']],
                    'end' => ['$lte' => $params['end']],
                ]],
                ['$project' => [
                    '_id' => 0,
                    'timeFrame' => [
                        'start' => '$start',
                        'end' => '$end',
                    ],
                    'value' => ['$multiply' => [['$cond' => [['$eq' => ['$totalSales', 0]], 0, ['$divide' => ['$laborCost', '$totalSales']]]], 100]]
                ]],
            ];

        }

        if ($params['timeInterval'] == 'day') {

            $aggregateBaseQuery = [
                ['$match' => [
                    'business_id' => $params['businessId'],
                    'start' => ['$gte' => $params['start']],
                    'end' => ['$lte' => $params['end']],
                ]],
                ['$group' => [
                    '_id' => '$day',
                    'business_id' => ['$first' => '$business_id'],
                    'day' => ['$first' => '$day'],
                    'totalSales' => ['$sum' => '$totalSales'],
                    'laborCost' => ['$sum' => '$laborCost'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromString' => ['dateString' => '$day']]]],
                        'end' => ['$dateToString' => ['timezone' => '+24:00', 'date' => ['$dateFromString' => ['dateString' => '$day']]]],
                    ],
                    'value' => ['$multiply' => [['$cond' => [['$eq' => ['$totalSales', 0]], 0, ['$divide' => ['$laborCost', '$totalSales']]]], 100]]
                ]],
            ];

        }

        if ($params['timeInterval'] == 'week') {

            $aggregateBaseQuery = [
                ['$match' => [
                    'business_id' => $params['businessId'],
                    'start' => ['$gte' => $params['start']],
                    'end' => ['$lte' => $params['end']],
                ]],
                ['$project' => [
                    'start' => 1,
                    'end' => 1,
                    'laborCost' => 1,
                    'totalSales' => 1,
                    'week' => ['$isoWeek' => ['$dateFromString' => ['dateString' => '$day']]],
                    'year' => ['$isoWeekYear' => ['$dateFromString' => ['dateString' => '$day']]]
                ]],
                ['$group' => [
                    '_id' => ['week' => '$week', 'year' => '$year'],
                    'totalSales' => ['$sum' => '$totalSales'],
                    'laborCost' => ['$sum' => '$laborCost'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromParts' => ['isoWeekYear' => '$_id.year', 'isoWeek' => '$_id.week']]]],
                        'end' => ['$dateToString' => ['date' => ['$dateFromParts' => ['isoWeekYear' => '$_id.year', 'isoWeek' => '$_id.week', 'isoDayOfWeek' => 7]]]]
                    ],
                    'value' => ['$multiply' => [['$cond' => [['$eq' => ['$totalSales', 0]], 0, ['$divide' => ['$laborCost', '$totalSales']]]], 100]]
                ]],
            ];

        }

        if ($params['timeInterval'] == 'month') {

            $aggregateBaseQuery = [
                ['$match' => [
                    'business_id' => $params['businessId'],
                    'start' => ['$gte' => $params['start']],
                    'end' => ['$lte' => $params['end']],
                ]],
                ['$project' => [
                    'start' => 1,
                    'end' => 1,
                    'laborCost' => 1,
                    'totalSales' => 1,
                    'dateParts' => ['$dateToParts' => ['date' => ['$dateFromString' => ['dateString' => '$day']]]]
                ]],
                ['$group' => [
                    '_id' => ['month' => '$dateParts.month', 'year' => '$dateParts.year'],
                    'totalSales' => ['$sum' => '$totalSales'],
                    'laborCost' => ['$sum' => '$laborCost'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromParts' => ['year' => '$_id.year', 'month' => '$_id.month', 'day' => 1]]]],
                        'end' => ['$dateToString' => ['date' => ['$dateFromParts' => ['year' => '$_id.year', 'month' => ['$add' => ['$_id.month', 1]], 'day' => 0]]]],

                    ],
                    'value' => ['$multiply' => [['$cond' => [['$eq' => ['$totalSales', 0]], 0, ['$divide' => ['$laborCost', '$totalSales']]]], 100]]
                ]],
            ];

        }

        $aggregateCountQuery = array_merge($aggregateBaseQuery, [['$count' => 'total']]);

        $aggregateResultQuery = array_merge($aggregateBaseQuery, [
            ['$skip' =>  intval($params['offset'])],
            ['$limit' => intval($params['limit'])],
        ]);

        return [$aggregateCountQuery, $aggregateResultQuery];

    }
}
