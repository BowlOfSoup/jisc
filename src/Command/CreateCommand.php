<?php

namespace Jisc\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\HttpFoundation\Response;

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
        $responseStatusCode = null;

        if (null !== $this->input->getOption(static::OPTION_SINGLE_TASK)) {
            $subTasks[] = $this->input->getOption(static::OPTION_SINGLE_TASK);
        }

        while (Response::HTTP_UNAUTHORIZED === $responseStatusCode || null === $responseStatusCode) {
            $responseStatusCode = $this->makeRequests($subTasks);
        }

        $this->line();
        $this->style->success('Sub-task(s) created');

        if (!$this->helper->ask($this->input, $this->output, new ConfirmationQuestion('Create another task? [y/N]: ', false, '/^(y|j)/i'))) {
            return;
        }

        $this->reset();
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

        foreach ($subTasks as $subTaskString) {
            try {
                $response = $client->request('POST', getenv('JIRA_URL') . $this->fullCreateUri, [
                    static::REQUEST_AUTH => $this->getAuth(),
                    static::REQUEST_HEADERS => $this->getHeaders(),
                    static::REQUEST_BODY => $this->preparePayload($subTaskString)
                ]);

                $responseStatusCode = $response->getStatusCode();
            } catch (RequestException $e) {
                $this->handleRequestException($e);
            }
        }

        return $responseStatusCode;
    }

    /**
     * @param string $subTaskString
     *
     * @return string
     */
    private function preparePayload(string $subTaskString): string
    {
        $payload = $this->getFileContent(__DIR__ . '/../Resources/templates/createSubTaskPayload.json');

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

        $this->line();

        $this->input->setOption(static::OPTION_STORY, null);
        $this->input->setOption(static::OPTION_PROJECT_KEY, null);

        $this->enterTaskDetails($suggestedStory);
        $this->createSubTasks();
    }
}
