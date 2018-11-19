<?php

namespace App\Service;

use Symfony\Component\Console\Output\OutputInterface;

interface ReportGeneratorInterface
{
    /**
     * Generate report data
     */
    public function generate(OutputInterface $output);
}
