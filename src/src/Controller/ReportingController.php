<?php

namespace App\Controller;

use App\Service\LcpReportManager;
use App\Service\FcpReportManager;
use App\Service\EgsReportManager;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Entry point for the reporting api. Request parameters are passed off to the relavent report manager and results are returned in the response.
 */
class ReportingController extends AbstractController
{
    private $dm;

    private $lcpReportManager;

    private $fcpReportManager;

    private $egsReportManager;

    public function __construct(DocumentManager $dm, LcpReportManager $lcpReportManager, FcpReportManager $fcpReportManager, EgsReportManager $egsReportManager)
    {
        $this->dm = $dm;
        $this->lcpReportManager = $lcpReportManager;
        $this->fcpReportManager = $fcpReportManager;
        $this->egsReportManager = $egsReportManager;
    }

    /**
     * @Route("/reporting", methods={"GET"}, name="reporting")
     */
    public function report(Request $request)
    {
        $params = $this->extractParams($request);

        $reportTypeMapping = [
            'LCP' => $this->lcpReportManager,
            'FCP' => $this->fcpReportManager,
            'EGS' => $this->egsReportManager,
        ];

        $reportResults = $reportTypeMapping[$params['reportType']]->find($params);

        $response = new Response(
            json_encode($reportResults),
            200,
            ['Content-Type' => 'application/json']
        );

        return $response;
    }

    private function extractParams(Request $request)
    {
        return [
            'reportType' => $request->query->get('report'),
            'businessId' => $request->query->get('business_id'),
            'start' => $request->query->get('start'),
            'end' => $request->query->get('end'),
            'timeInterval' => $request->query->get('timeInterval'),
            'limit' => $request->query->get('limit', 100),
            'offset' => $request->query->get('offset', 0),
        ];
    }
}
