<?php

namespace App\Command;

use App\Service\SyncManager;
use App\Service\LcpReportGenerator;
use App\Service\EgsReportGenerator;
use App\Service\FcpReportGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Executable command which generates hourly reporting data for LCP, FCP, and EGS.
 * In production I would execute this every new hour or day
 */
class GenerateReportCommand extends Command
{
    private $syncManager;

    private $lcpReportGenerator;

    private $fcpReportGenerator;

    private $egsReportGenerator;

    public function __construct(SyncManager $syncManager, LcpReportGenerator $lcpReportGenerator, FcpReportGenerator $fcpReportGenerator, EgsReportGenerator $egsReportGenerator)
    {
        parent::__construct();

        $this->syncManager = $syncManager;

        $this->lcpReportGenerator = $lcpReportGenerator;

        $this->fcpReportGenerator = $fcpReportGenerator;

        $this->egsReportGenerator = $egsReportGenerator;
    }

    protected function configure()
    {
        $this
            ->setName('avero:generate-report')

            ->setDescription('Import avero data and generate reports.')

            ->setHelp('This command allows you to import all avero data and generate three reports.')

            ->addArgument('averoAuth',  InputArgument::REQUIRED, 'Avero api authorization key')

        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        ini_set('memory_limit', '2048M');

        $averoAuth = $input->getArgument('averoAuth');

        // first sync with averos test data set
        $output->writeln('<info>Syncronizing Data Set</info>');
        $this->syncManager->sync($averoAuth, $output);

        // now generate the reports
        $output->writeln('<info>LCP Report Generating</info>');
        $this->lcpReportGenerator->generate($output);

        $output->writeln('<info>FCP Report Generating</info>');
        $this->fcpReportGenerator->generate($output);

        $output->writeln('<info>EGS Report Generating</info>');
        $this->egsReportGenerator->generate($output);

    }
}
