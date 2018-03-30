<?php

namespace Jisc\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Finder\Finder;

class CreateCommand extends AbstractCommand
{
    const OPTION_SINGLE_TASK = 'task';

    /** @var string */
    private $fullCreateUri = '/rest/api/2/issue/';

    protected function configure()
    {
        $this
            ->setName('subtask:create')
            ->setDescription('Creates new subtasks.')
        ;

        parent::configure();

        $this
            ->addOption(static::OPTION_SINGLE_TASK, 't', InputOption::VALUE_OPTIONAL, 'Add this single task to given story.')
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

    private function createSubTasks()
    {
        $subTasks = array();
        $requestSuccess = false;

        $hasOptionForSingleTask = (null !== $this->input->getOption(static::OPTION_SINGLE_TASK));
        if ($hasOptionForSingleTask) {
            $subTasks[] = $this->input->getOption(static::OPTION_SINGLE_TASK);
        } else {
            $this->line();
            $question = new ChoiceQuestion('Select a task set: ', $this->getTaskFiles(), 0);
            $question->setMultiselect(true);

            $taskSets = $this->helper->ask($this->input, $this->output, $question);

            foreach ($taskSets as $taskSet) {
                $this->style->writeln(
                    PHP_EOL . sprintf(
                        'Making sub-tasks for <info>%s</info> with task-set: <info>%s</info>.',
                        $this->input->getOption(static::OPTION_STORY),
                        $taskSet
                    )
                );

                $subTasks = $this->getFileContent(__DIR__ . static::DIR_RESOURCES . static::DIR_SETS . $taskSet, static::FILE_READ_ARRAY);
            }
        }

        while (!$requestSuccess) {
            $requestSuccess = $this->makeRequests($subTasks);
        }

        $this->style->success('Sub-task(s) created');

        if ($hasOptionForSingleTask ||
            !$this->helper->ask($this->input, $this->output, new ConfirmationQuestion('Create more tasks? [y/N]: ', false, '/^(y|j)/i'))
        ) {
            return;
        }

        $this->reset();
    }

    /**
     * @return array
     */
    private function getTaskFiles(): array
    {
        $taskFiles = [];

        $finder = new Finder();
        $finder->files()->in(__DIR__ . static::DIR_RESOURCES . static::DIR_SETS);

        foreach ($finder as $file) {
            $taskFiles[] = $file->getFileName();
        }

        return $taskFiles;
    }

    /**
     * @param array $subTasks
     *
     * @return string
     */
    private function makeRequests(array $subTasks): string
    {
        $responseStatusCode = null;
        $client = new Client();

        $progressBar = new ProgressBar($this->output, count($subTasks));
        $progressBar->start();

        foreach ($subTasks as $subTaskString) {
            try {
                $client->request('POST', getenv('JIRA_URL') . $this->fullCreateUri, [
                    static::REQUEST_AUTH => $this->getAuth(),
                    static::REQUEST_HEADERS => $this->getHeaders(),
                    static::REQUEST_BODY => $this->preparePayload($subTaskString)
                ]);

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
     * @param string $subTaskString
     *
     * @return string
     */
    private function preparePayload(string $subTaskString): string
    {
        $payload = $this->getFileContent(__DIR__ . static::DIR_RESOURCES . static::DIR_TEMPLATES . 'createSubTaskPayload.json');

        $subTaskString = preg_replace('/\r|\n/', '', $subTaskString);

        $payload = str_replace('%PK%', $this->input->getOption(static::OPTION_PROJECT_KEY), $payload);
        $payload = str_replace('%PARENTSTORY%', $this->input->getOption(static::OPTION_STORY), $payload);
        $payload = str_replace('%SUMMARY%', $subTaskString, $payload);
        $payload = str_replace('%DESCRIPTION%', $subTaskString, $payload);
        $payload = str_replace('%ISSUETYPE%', '5', $payload);

        return $payload;
    }

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
