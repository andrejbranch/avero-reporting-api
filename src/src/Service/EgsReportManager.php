<?php

namespace App\Service;

use App\Service\ReportManagerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles querying the egs mongo collection using parameters passed in from the ReportingController.
 * Hourly data needs minimal manipulation as the EgsReportGenerator has stored this information.
 * Daily, weekly, and monthly data are aggregated using this hourly data.
 *
 * @see https://docs.mongodb.com/manual/aggregation for more information on mongos aggregation pipeline
 */
class EgsReportManager implements ReportManagerInterface
{
    private $dm;

    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function find($params)
    {
        $egsCollection = $this->dm->getConnection()->selectDatabase('avero')->selectCollection('egs');

        $start = new \DateTime($params['start']);
        $end = new \DateTime($params['end']);

        $params['start'] = $start->format('Y-m-d\TH:i:s.000\Z');
        $params['end'] = $end->format('Y-m-d\TH:i:s.000\Z');

        list($aggregateCountQuery, $aggregateResultQuery) = $this->getIntervalQueries($params);

        $cursor = $egsCollection->aggregate($aggregateCountQuery, ['cursor' => true]);
        $count = $cursor->getSingleResult()['total'];

        $cursor = $egsCollection->aggregate($aggregateResultQuery, ['cursor' => true]);

        $results = [
            'report' => 'EGS',
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
                    'employee' => '$employee',
                    'timeFrame' => [
                        'start' => '$start',
                        'end' => '$end',
                    ],
                    'value' => '$sales'
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
                    '_id' => ['day' => '$day', 'employee' => '$employee_id'],
                    'business_id' => ['$first' => '$business_id'],
                    'day' => ['$first' => '$day'],
                    'employee' => ['$first' => '$employee'],
                    'sales' => ['$sum' => '$sales'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'employee' => 1,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromString' => ['dateString' => '$day']]]],
                        'end' => ['$dateToString' => ['timezone' => '+24:00', 'date' => ['$dateFromString' => ['dateString' => '$day']]]],
                    ],
                    'value' => '$sales'
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
                    'sales' => 1,
                    'employee_id' => 1,
                    'employee' => 1,
                    'week' => ['$isoWeek' => ['$dateFromString' => ['dateString' => '$day']]],
                    'year' => ['$isoWeekYear' => ['$dateFromString' => ['dateString' => '$day']]]
                ]],
                ['$group' => [
                    '_id' => ['week' => '$week', 'year' => '$year', 'employee' => '$employee_id'],
                    'employee' => ['$first' => '$employee'],
                    'sales' => ['$sum' => '$sales'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'employee' => 1,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromParts' => ['isoWeekYear' => '$_id.year', 'isoWeek' => '$_id.week']]]],
                        'end' => ['$dateToString' => ['date' => ['$dateFromParts' => ['isoWeekYear' => '$_id.year', 'isoWeek' => '$_id.week', 'isoDayOfWeek' => 7]]]]
                    ],
                    'value' => '$sales'
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
                    'sales' => 1,
                    'employee_id' => 1,
                    'employee' => 1,
                    'dateParts' => ['$dateToParts' => ['date' => ['$dateFromString' => ['dateString' => '$day']]]]
                ]],
                ['$group' => [
                    '_id' => ['month' => '$dateParts.month', 'year' => '$dateParts.year', 'employee' => '$employee_id'],
                    'employee' => ['$first' => '$employee'],
                    'sales' => ['$sum' => '$sales'],
                ]],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'employee' => 1,
                    'timeFrame' => [
                        'start' => ['$dateToString' => ['date' => ['$dateFromParts' => ['year' => '$_id.year', 'month' => '$_id.month', 'day' => 1]]]],
                        'end' => ['$dateToString' => ['date' => ['$dateFromParts' => ['year' => '$_id.year', 'month' => ['$add' => ['$_id.month', 1]], 'day' => 0]]]],

                    ],
                    'value' => '$sales'
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
