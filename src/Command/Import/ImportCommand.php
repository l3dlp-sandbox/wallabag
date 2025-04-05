<?php

namespace Wallabag\Command\Import;

use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Wallabag\Entity\User;
use Wallabag\Import\ChromeImport;
use Wallabag\Import\DeliciousImport;
use Wallabag\Import\ElcuratorImport;
use Wallabag\Import\FirefoxImport;
use Wallabag\Import\InstapaperImport;
use Wallabag\Import\OmnivoreImport;
use Wallabag\Import\PinboardImport;
use Wallabag\Import\PocketHtmlImport;
use Wallabag\Import\ReadabilityImport;
use Wallabag\Import\ShaarliImport;
use Wallabag\Import\WallabagV1Import;
use Wallabag\Import\WallabagV2Import;
use Wallabag\Repository\UserRepository;

class ImportCommand extends Command
{
    protected static $defaultName = 'wallabag:import';
    protected static $defaultDescription = 'Import entries from a JSON export';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private UserRepository $userRepository,
        private WallabagV2Import $wallabagV2Import,
        private FirefoxImport $firefoxImport,
        private ChromeImport $chromeImport,
        private ReadabilityImport $readabilityImport,
        private InstapaperImport $instapaperImport,
        private PinboardImport $pinboardImport,
        private DeliciousImport $deliciousImport,
        private WallabagV1Import $wallabagV1Import,
        private ElcuratorImport $elcuratorImport,
        private ShaarliImport $shaarliImport,
        private PocketHtmlImport $pocketHtmlImport,
        private OmnivoreImport $omnivoreImport,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'User to populate')
            ->addArgument('filepath', InputArgument::REQUIRED, 'Path to the JSON file')
            ->addOption('importer', null, InputOption::VALUE_OPTIONAL, 'The importer to use: v1, v2, instapaper, pinboard, delicious, readability, firefox, chrome, elcurator, shaarli or pocket', 'v1')
            ->addOption('markAsRead', null, InputOption::VALUE_OPTIONAL, 'Mark all entries as read', false)
            ->addOption('useUserId', null, InputOption::VALUE_NONE, 'Use user id instead of username to find account')
            ->addOption('disableContentUpdate', null, InputOption::VALUE_NONE, 'Disable fetching updated content from URL')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Start : ' . (new \DateTime())->format('d-m-Y G:i:s') . ' ---');

        if (!file_exists($input->getArgument('filepath'))) {
            throw new Exception(\sprintf('File "%s" not found', $input->getArgument('filepath')));
        }

        // Turning off doctrine default logs queries for saving memory
        $middlewares = $this->entityManager->getConnection()->getConfiguration()->getMiddlewares();
        $middlewaresWithoutLogging = array_filter($middlewares, fn (Middleware $middleware) => !$middleware instanceof LoggingMiddleware);
        $this->entityManager->getConnection()->getConfiguration()->setMiddlewares($middlewaresWithoutLogging);

        if ($input->getOption('useUserId')) {
            $entityUser = $this->userRepository->findOneById($input->getArgument('username'));
        } else {
            $entityUser = $this->userRepository->findOneByUsername($input->getArgument('username'));
        }

        if (!\is_object($entityUser)) {
            throw new Exception(\sprintf('User "%s" not found', $input->getArgument('username')));
        }

        // Authenticate user for paywalled websites
        $token = new UsernamePasswordToken(
            $entityUser,
            'main',
            $entityUser->getRoles());

        $this->tokenStorage->setToken($token);
        $user = $this->tokenStorage->getToken()->getUser();

        $import = match ($input->getOption('importer')) {
            'v2' => $this->wallabagV2Import,
            'firefox' => $this->firefoxImport,
            'chrome' => $this->chromeImport,
            'readability' => $this->readabilityImport,
            'instapaper' => $this->instapaperImport,
            'pinboard' => $this->pinboardImport,
            'delicious' => $this->deliciousImport,
            'elcurator' => $this->elcuratorImport,
            'shaarli' => $this->shaarliImport,
            'pocket' => $this->pocketHtmlImport,
            'omnivore' => $this->omnivoreImport,
            default => $this->wallabagV1Import,
        };

        $import->setMarkAsRead($input->getOption('markAsRead'));
        $import->setDisableContentUpdate($input->getOption('disableContentUpdate'));
        $import->setUser($user);

        $res = $import
            ->setFilepath($input->getArgument('filepath'))
            ->import();

        if (true === $res) {
            $summary = $import->getSummary();
            $output->writeln('<info>' . $summary['imported'] . ' imported</info>');
            $output->writeln('<comment>' . $summary['skipped'] . ' already saved</comment>');
        }

        $this->entityManager->clear();

        $output->writeln('End : ' . (new \DateTime())->format('d-m-Y G:i:s') . ' ---');

        return 0;
    }
}
