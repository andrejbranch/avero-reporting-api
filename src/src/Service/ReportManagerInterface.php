<?php

namespace App\Service;

use Symfony\Component\Console\Output\OutputInterface;

interface ReportManagerInterface
{
    /**
     * Aggregate report based on time interval
     */
    public function find($params);
}
