<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Entity\Repository\JobRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('jms-job-queue:clean-up')
            ->setDescription('Cleans up jobs which exceed the maximum retention time.')
            ->addOption('max-retention', null, InputOption::VALUE_REQUIRED, 'The maximum retention time (value must be parsable by DateTime).', '30 days')
            ->addOption('per-call', null, InputOption::VALUE_REQUIRED, 'The maximum number of jobs to clean-up per call.', 1000)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->getContainer()->get('doctrine');

        /** @var EntityManager $em */
        $em = $registry->getManagerForClass('JMSJobQueueBundle:Job');
        $con = $em->getConnection();

        $this->cleanUpExpiredJobs($em, $con, $input);
        $this->collectStaleJobs($em);
    }

    private function collectStaleJobs(EntityManager $em)
    {
        /** @var JobRepository $repository */
        $repository = $em->getRepository(Job::class);

        foreach ($this->findStaleJobs($em) as $job) {
            if ($job->isRetried()) {
                continue;
            }

            $repository->closeJob($job, Job::STATE_INCOMPLETE);
        }
    }

    /**
     * @return Job[]
     */
    private function findStaleJobs(EntityManager $em)
    {
        $excludedIds = array(-1);

        do {
            $em->clear();

            /** @var Job $job */
            $job = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j
                                      WHERE j.state = :running AND j.workerName IS NOT NULL AND j.checkedAt < :maxAge
                                                AND j.id NOT IN (:excludedIds)")
                ->setParameter('running', Job::STATE_RUNNING)
                ->setParameter('maxAge', new \DateTime('-5 minutes'), 'datetime')
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($job !== null) {
                $excludedIds[] = $job->getId();

                yield $job;
            }
        } while ($job !== null);
    }

    private function cleanUpExpiredJobs(EntityManager $em, Connection $con, InputInterface $input)
    {
        $incomingDepsSql = $con->getDatabasePlatform()->modifyLimitQuery("SELECT 1 FROM jms_job_dependencies WHERE dest_job_id = :id", 1);

        $count = 0;
        foreach ($this->findExpiredJobs($em, $input) as $job) {
            /** @var Job $job */

            $result = $con->executeQuery($incomingDepsSql, array('id' => $job->getId()));
            if ($result->fetchColumn() !== false) {
                // There are still other jobs that depend on this, we will come back later.
                continue;
            }

            $count++;
            $em->remove($job);

            if ($count >= $input->getOption('per-call')) {
                break;
            }
        }

        $em->flush();
    }

    private function findExpiredJobs(EntityManager $em, InputInterface $input)
    {
        $maxRetentionTime = new \DateTime('-'.$input->getOption('max-retention'));
        $excludedIds = array(-1);

        do {
            $jobs = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL AND j.id NOT IN (:excludedIds)")
                ->setParameter('maxRetentionTime', $maxRetentionTime)
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(100)
                ->getResult();

            foreach ($jobs as $job) {
                $excludedIds[] = $job->getId();

                yield $job;
            }
        } while (count($jobs) > 0);

        $excludedIds = array(-1);
        do {
            $jobs = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.state = :canceled AND j.createdAt < :maxRetentionTime AND j.originalJob IS NULL AND j.id NOT IN (:excludedIds)")
                ->setParameter('maxRetentionTime', $maxRetentionTime)
                ->setParameter('canceled', Job::STATE_CANCELED)
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(100)
                ->getResult();

            foreach ($jobs as $job) {
                $excludedIds[] = $job->getId();

                yield $job;
            }
        } while (count($jobs) > 0);
    }
}