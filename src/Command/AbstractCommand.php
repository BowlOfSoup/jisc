<?php

namespace Jisc\Command;

use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AbstractCommand extends Command
{
    const OPTION_USER = 'user';
    const OPTION_PASSWORD = 'password';
    const OPTION_STORY = 'story';
    const OPTION_PROJECT_KEY = 'key';

    const REQUEST_AUTH = 'auth';
    const REQUEST_HEADERS = 'headers';
    const REQUEST_BODY = 'body';

    const FILE_READ_ARRAY = true;

    const DIR_RESOURCES = '/../Resources/';
    const DIR_TEMPLATES = 'templates/';
    const DIR_SETS = 'sets/';

    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    protected $style;

    /** @var \Symfony\Component\Console\Helper\QuestionHelper */
    protected $helper;

    /** @var \Symfony\Component\Console\Input\InputInterface */
    protected $input;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    protected $output;

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

    protected function line()
    {
        $this->output->write(PHP_EOL);
    }

    /**
     * @return array
     */
    protected function getAuth(): array
    {
        return [$this->input->getOption(static::OPTION_USER), $this->input->getOption(static::OPTION_PASSWORD)];
    }

    /**
     * @return array
     */
    protected function getHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
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

            exit;
        }

        throw $e;
    }

    /**
     * @param string $fullPath
     * @param bool $readInArray
     *
     * @throws \InvalidArgumentException
     *
     * @return string|array
     */
    protected function getFileContent(string $fullPath, $readInArray = false)
    {
        if (!file_exists($fullPath)) {
            throw new \InvalidArgumentException(sprintf('File %s does not exist', $fullPath));
        }

        if ($readInArray) {
            return file($fullPath);
        } else {
            return file_get_contents($fullPath);
        }
    }

    protected function enterPassword()
    {
        $question = new Question('Jira password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        $this->input->setOption(static::OPTION_PASSWORD, $this->helper->ask($this->input, $this->output, $question));
    }

    /**
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

    private function handleInput()
    {
        $systemUser = trim($this->runCommand('whoami'));

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

    /**
     * @param string $command
     *
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     *
     * @return string
     */
    private function runCommand(string $command): string
    {
        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
