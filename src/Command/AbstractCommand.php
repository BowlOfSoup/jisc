<?php

namespace Jisc\Command;

use GuzzleHttp\Exception\RequestException;
use Jisc\Service\RequestService;
use Jisc\Service\SystemService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class AbstractCommand extends Command
{
    const OPTION_USER = 'user';
    const OPTION_PASSWORD = 'password';
    const OPTION_STORY = 'story';
    const OPTION_PROJECT_KEY = 'key';

    const DIR_RESOURCES = '/../Resources/';
    const DIR_TEMPLATES = 'templates/';
    const DIR_SETS = 'sets/';

    /** @var \Jisc\Service\SystemService */
    protected $systemService;

    /** @var \Jisc\Service\RequestService */
    protected $requestService;

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    protected $style;

    /** @var \Symfony\Component\Console\Helper\QuestionHelper */
    protected $helper;

    /** @var \Symfony\Component\Console\Input\InputInterface */
    protected $input;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    protected $output;

    /**
     * @param \Jisc\Service\SystemService $systemService
     * @param \Jisc\Service\RequestService $requestService
     */
    public function __construct(
        SystemService $systemService,
        RequestService $requestService
    ) {
        parent::__construct();

        $this->systemService = $systemService;
        $this->requestService = $requestService;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function setDependencies(InputInterface $input, OutputInterface $output)
    {
        $this->style = new SymfonyStyle($input, $output);
        $this->helper = $this->getHelper('question');
        $this->input = $input;
        $this->output = $output;
    }

    protected function configure()
    {
        $this
            ->addOption(static::OPTION_USER, 'u', InputOption::VALUE_OPTIONAL, 'Username to authenticate with Jira instance.')
            ->addOption(static::OPTION_PASSWORD, 'p', InputOption::VALUE_OPTIONAL, 'Password to authenticate with Jira instance.')
            ->addOption(static::OPTION_STORY, 's', InputOption::VALUE_OPTIONAL, '(Parent) Story to use to do actions on with this script.')
            ->addOption(static::OPTION_PROJECT_KEY, 'k', InputOption::VALUE_OPTIONAL, 'Project key for story.');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null === $this->input) {
            throw new \RuntimeException('Error: Make sure you call setDependencies()');
        }

        $this->handleInput();
    }

    /**
     * Write an empty line to the output.
     */
    protected function line()
    {
        $this->output->write(PHP_EOL);
    }

    /**
     * Get authentication key/value from user input.
     *
     * @return array
     */
    protected function getAuth(): array
    {
        return [$this->input->getOption(static::OPTION_USER), $this->input->getOption(static::OPTION_PASSWORD)];
    }

    /**
     * Handle generic Jira Exceptions.
     *
     * @param \GuzzleHttp\Exception\RequestException $e
     *
     * @throws \GuzzleHttp\Exception\RequestException
     */
    protected function handleRequestException(RequestException $e)
    {
        if (strpos($e->getMessage(), '401 Unauthorized') > 0) {
            $this->line();
            $this->style->warning('Wrong password, enter the correct password.');

            $this->enterPassword();

            return;
        }

        if (strpos($e->getMessage(), '403 Forbidden') > 0) {
            $this->line();
            $this->style->error('Too many wrong attempts. Login into Jira manually and complete the captcha.');

            exit(1);
        }

        throw $e;
    }

    /**
     * User input; Password.
     *
     * Needs to be called separately once when starting script and when entered password was wrong according to Jira.
     */
    protected function enterPassword()
    {
        $question = new Question('Jira password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        $this->input->setOption(static::OPTION_PASSWORD, $this->helper->ask($this->input, $this->output, $question));
    }

    /**
     * User input; task related questions, story number and project key.
     *
     * Needs to be called separately once when starting script and when wanting to add more sub-tasks for a specific story.
     *
     * @param string|null $suggestedStory
     */
    protected function enterTaskDetails(string $suggestedStory = null)
    {
        while (null === $this->input->getOption(static::OPTION_STORY)) {
            $question = new Question('Story: ');

            if (null !== $suggestedStory) {
                $question = new Question(sprintf('Story [%s]: ', $suggestedStory), $suggestedStory);
            }

            $this->input->setOption(static::OPTION_STORY, $this->helper->ask($this->input, $this->output, $question));
        }

        $splitStoryString = explode('-', $this->input->getOption(static::OPTION_STORY));
        $suggestedKey = reset($splitStoryString);

        while (null === $this->input->getOption(static::OPTION_PROJECT_KEY)) {
            $this->input->setOption(
                static::OPTION_PROJECT_KEY,
                $this->helper->ask($this->input, $this->output, new Question(sprintf('Project key [%s]: ', $suggestedKey), $suggestedKey))
            );
        }
    }

    /**
     * Initialization method to start-up the questioning when starting script.
     */
    private function handleInput()
    {
        $systemUser = trim($this->systemService->runCommand('whoami'));

        while (null === $this->input->getOption(static::OPTION_USER)) {
            $this->input->setOption(
                static::OPTION_USER,
                $this->helper->ask($this->input, $this->output, new Question(sprintf('Jira username [%s]: ', $systemUser), $systemUser))
            );
        }

        while (null === $this->input->getOption(static::OPTION_PASSWORD)) {
            $this->enterPassword();
        }

        $this->enterTaskDetails();
    }
}
