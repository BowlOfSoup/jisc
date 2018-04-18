<?php

namespace Jisc\Command;

use GuzzleHttp\Exception\RequestException;
use Jisc\Service\RequestService;
use Jisc\Service\SystemService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;

class CreateCommand extends AbstractCommand
{
    const ARGUMENT_SKIP_CONFIRMATION = 'skip-confirmation';
    const OPTION_SINGLE_TASK = 'task';
    const OPTION_FILE_SET = 'file';

    const DIR_USER_SETS = '/.jisc/';

    const CONFIRMATION_REGEX_YES = '/^(y|j)/i';

    protected function configure()
    {
        $this
            ->setName('subtask:create')
            ->setDescription('Creates new subtasks.')
        ;

        parent::configure();

        $this
            ->addOption(static::OPTION_SINGLE_TASK, 't', InputOption::VALUE_OPTIONAL, 'Add this single task to given story.')
            ->addOption(static::OPTION_FILE_SET, 'f', InputOption::VALUE_OPTIONAL, '(Only) the filename for a file containing sub-tasks with one task per line.')
            ->addOption(static::ARGUMENT_SKIP_CONFIRMATION, '!', InputOption::VALUE_NONE, 'If specified, no confirmation is needed when creating subtasks.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setDependencies($input, $output);

        $this->style->title('Generate sub-tasks for a Jira story.');

        parent::execute($input, $output);

        $this->createSubTasks();
    }

    /**
     * Wrapper method to make distinction between adding one or multiple sub-tasks, and actually adding them.
     */
    private function createSubTasks()
    {
        $requestSuccess = false;

        $hasOptionForSingleTask = (null !== $this->input->getOption(static::OPTION_SINGLE_TASK));
        if ($hasOptionForSingleTask) {
            // Get a single task.
            $subTasks[] = $this->input->getOption(static::OPTION_SINGLE_TASK);
        } else {
            // Get multiple sub-tasks.
            $subTasks = $this->getMultipleSubTasks();
        }

        while (!$requestSuccess) {
            $requestSuccess = $this->makeRequests($subTasks);
        }

        $this->style->success('Sub-task(s) created');

        if ($hasOptionForSingleTask ||
            !$this->helper->ask($this->input, $this->output, new ConfirmationQuestion('Create more tasks? [y/N]: ', false, static::CONFIRMATION_REGEX_YES))
        ) {
            return;
        }

        $this->reset();
    }

    /**
     * Possibility to make a selection for which sub-task SET(S) the user wants to add.
     *
     * @return array
     */
    private function getMultipleSubTasks(): array
    {
        $subTasks = [];
        $mergedSetOfSubTasks = [];

        $taskSetFileFromOption = $this->input->getOption(static::OPTION_FILE_SET);
        if (null !== $taskSetFileFromOption) {
            // A file, given via option, with a set of tasks.
            $taskSets[] = $taskSetFileFromOption;
        } else {
            $this->line();

            // Choose a file with a set of tasks.
            $question = new ChoiceQuestion('Select a task set: ', $this->getTaskFiles(), 0);
            $question->setMultiselect(true);

            $taskSets = $this->helper->ask($this->input, $this->output, $question);
        }

        foreach ($taskSets as $taskSet) {
            $this->style->writeln(
                PHP_EOL . sprintf(
                    'Making sub-tasks for <info>%s</info> with task-set: <info>%s</info>.',
                    $this->input->getOption(static::OPTION_STORY),
                    $taskSet
                )
            );

            $defaultTaskSetDir = __DIR__ . static::DIR_RESOURCES . static::DIR_SETS;
            if (file_exists($_SERVER['HOME'] . static::DIR_USER_SETS . $taskSet)) {
                $defaultTaskSetDir = $_SERVER['HOME'] . static::DIR_USER_SETS;
            }

            $subTasks = $this->systemService->getFileContent($defaultTaskSetDir . $taskSet, SystemService::FILE_READ_ARRAY);
            $mergedSetOfSubTasks = array_merge($this->filterSubTasks($subTasks), $mergedSetOfSubTasks);
        }

        return $mergedSetOfSubTasks;
    }

    /**
     * Get file names of the sub-tasks files.
     *
     * Can be the stock task sets, or user defined.
     *
     * @return array
     */
    private function getTaskFiles(): array
    {
        $taskFiles = [];
        $finder = new Finder();

        $finder->files()->in(__DIR__ . static::DIR_RESOURCES . static::DIR_SETS);
        if (is_dir($_SERVER['HOME'] . static::DIR_USER_SETS)) {
            $finder->files()->in($_SERVER['HOME'] . static::DIR_USER_SETS);
        }
        foreach ($finder as $file) {
            $taskFiles[] = $file->getFileName();
        }

        sort($taskFiles);

        return $taskFiles;
    }

    /**
     * Possibility to decide per chosen sub-task set which sub-tasks actually need to be added.
     *
     * Like a filter on the chosen set.
     *
     * @param array $subTasks
     *
     * @return array
     */
    private function filterSubTasks(array $subTasks)
    {
        return array_filter($subTasks, function($subTask) {
            if (empty($subTask)) {
                return false;
            }

            if ($this->input->getOption(static::ARGUMENT_SKIP_CONFIRMATION)) {
                return true;
            }

            return $this->helper->ask($this->input, $this->output, new ConfirmationQuestion($subTask . ' [Y/n]: ', true, static::CONFIRMATION_REGEX_YES));
        });
    }

    /**
     * Make the actual request per sub-task to be created.
     *
     * @param array $subTasks
     *
     * @return string
     */
    private function makeRequests(array $subTasks): string
    {
        $responseStatusCode = null;

        $this->line();
        $progressBar = new ProgressBar($this->output, count($subTasks));
        $progressBar->start();

        foreach ($subTasks as $subTaskString) {
            try {
                $this->requestService->httpRequest(
                    Request::METHOD_POST,
                    getenv('JIRA_URL') . RequestService::JIRA_CREATE_URI,
                    [
                        RequestService::REQUEST_AUTH => $this->getAuth(),
                        RequestService::REQUEST_HEADERS => RequestService::HEADERS_DEFAULT,
                        RequestService::REQUEST_BODY => $this->preparePayload($subTaskString),
                    ]
                );

                $progressBar->advance();
            } catch (RequestException $e) {
                $this->handleRequestException($e);

                return false;
            }
        }

        $progressBar->finish();
        $this->line();

        return true;
    }

    /**
     * Replace placeholder values for the payload needed to make a create sub-tasks request to Jira.
     *
     * @param string $subTaskString
     *
     * @return string
     */
    private function preparePayload(string $subTaskString): string
    {
        $payload = $this->systemService->getFileContent(__DIR__ . static::DIR_RESOURCES . static::DIR_TEMPLATES . 'createSubTaskPayload.json');

        $payload = str_replace('%PK%', $this->input->getOption(static::OPTION_PROJECT_KEY), $payload);
        $payload = str_replace('%PARENTSTORY%', $this->input->getOption(static::OPTION_STORY), $payload);
        $payload = str_replace('%SUMMARY%', $subTaskString, $payload);
        $payload = str_replace('%DESCRIPTION%', $subTaskString, $payload);
        $payload = str_replace('%ISSUETYPE%', '5', $payload);

        return $payload;
    }

    /**
     * Reset the script to make it possible for the user to add different sub-tasks to different stories.
     */
    private function reset()
    {
        $suggestedStory = $this->input->getOption(static::OPTION_STORY);

        $this->input->setOption(static::OPTION_STORY, null);
        $this->input->setOption(static::OPTION_PROJECT_KEY, null);

        $this->line();
        $this->enterTaskDetails($suggestedStory);
        $this->createSubTasks();
    }
}
