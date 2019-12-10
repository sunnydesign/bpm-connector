#!/usr/bin/php
<?php

/**
 * Camunda Connector
 */

require __DIR__ . '/vendor/autoload.php';

use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Quancy\Logger\Logger;

// Config
$config = __DIR__ . '/config.php';
$config_env = __DIR__ . '/config.env.php';
if (is_file($config)) {
    require_once $config;
} elseif (is_file($config_env)) {
    require_once $config_env;
}

class CamundaConnector
{
    /** @var string */
    protected $camundaUrl;

    /** @var string */
    protected $externalTaskTopic;

    /** @var int */
    protected $lockDuration = 10000 * 60 * 60; // 1 hour

    /** @var string */
    protected $workerId;

    /** @var ExternalTaskService */
    protected $externalTaskService;

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {

        $this->initSignalHandler();
        $this->getOptions();
        $this->workerId = 'worker' . getmypid();
        $this->externalTaskService = new ExternalTaskService($this->camundaUrl);

        while (true) {
            // Quit on Ctrl+C
            pcntl_signal_dispatch();
            foreach ($this->fetchExternalTasks() as $externalTask) {
                $queue = $externalTask->variables->queue->value;
                Logger::log(
                        sprintf(
                            "Fetched and locked <%s> task <%s> of <%s> process instance <%s>",
                            $externalTask->topicName,
                            $externalTask->id,
                            $externalTask->processDefinitionKey,
                            $externalTask->processInstanceId
                        ),
                        'input',
                        $queue,
                        'bpm-connector',
                        0
                );

                call_user_func([$this, $this->topicNameToMethodName($externalTask->topicName)], $externalTask);
            }
            usleep(1000000);
        }
    }

    /**
     * Fetch and lock external tasks from Camunda
     *
     * @return array
     */
    protected function fetchExternalTasks(): array
    {
        // Fetch one external task of the given topic
        $externalTaskQueryRequest = (new ExternalTaskRequest())
            ->set('topics', [['topicName' => $this->externalTaskTopic, 'lockDuration' => $this->lockDuration]])
            ->set('workerId', $this->workerId)
            ->set('maxTasks', 1);
        $result = $this->externalTaskService->fetchAndLock($externalTaskQueryRequest);
        if (!is_array($result)) {
            $result = [];
        }
        return $result;
    }

    /**
     * Get the name of the method to use for handling an external task topic
     *
     * @param string $topicName
     * @return string
     */
    protected function topicNameToMethodName(string $topicName): string
    {
        return 'handleTask_' . strtr($topicName, ['-' => '_']);
    }

    /**
     * Initialize Unix signal handler
     *
     * ... to make sure we quit on Ctrl+C whenever we call pcntl_signal_dispatch().
     */
    protected function initSignalHandler(): void
    {
        pcntl_signal(SIGINT, function () {
            fwrite(STDERR, "Caught SIGINT - exitting\n");
            exit(128 + SIGINT);
        });
        pcntl_signal(SIGTERM, function () {
            fwrite(STDERR, "Caught SIGTERM - exitting\n");
            exit(128 + SIGTERM);
        });
    }

    /**
     * Get command line options
     */
    protected function getOptions(): void
    {
        // Get command line options
        $usageHelp = 'Usage: php worker.php --camunda-url="http://localhost:8080/engine-rest" --task-topic="asset-ingest"';
        $options = getopt('', ['camunda-url:', 'task-topic:', 'lock-duration:']);
        foreach (['camunda-url', 'task-topic'] as $optionName) {
            if (empty($options[$optionName])) {
                fwrite(STDERR, "Error: Missing option --$optionName.\n");
                fwrite(STDERR, $usageHelp . "\n");
                exit(1);
            }
        }
        $this->camundaUrl = $options['camunda-url'];
        $this->externalTaskTopic = $options['task-topic'];
        $methodName = $this->topicNameToMethodName($this->externalTaskTopic);
        if (!method_exists($this, $methodName)) {
            fwrite(STDERR, "Error: Wrong value for --task-topic. Method $methodName does not exist.\n");
            exit(1);
        }
        if (isset($options['lock-duration']) && (intval($options['lock-duration']) > 0)) {
            $this->lockDuration = intval($options['lock-duration']);
        }
    }

    /**
     * Topic Connector
     * Send task to Rabbit MQ
     *
     * @param object $externalTask
     * @throws Exception
     */
    protected function handleTask_connector($externalTask): void
    {
        // queue
        $queue = $externalTask->variables->queue->value;

        // incoming message from rabbit mq
        $incomingMessageAsString = $externalTask->variables->message->value ?? json_encode(['data'=>'', 'headers'=>'']);
        $incomingMessage = json_decode($incomingMessageAsString, true);

        // add external task id, process instance id, worker id in headers
        $incomingMessage['headers']['camundaExternalTaskId'] = $externalTask->id;
        $incomingMessage['headers']['camundaProcessInstanceId'] = $externalTask->processInstanceId;
        $incomingMessage['headers']['camundaWorkerId'] = $this->workerId;

        /****/
        $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
        $channel = $connection->channel();

        // включить режим работы канала с ожиданием подтверждения
        // при $nowait = true, сам метод не будет ждать ответа от сервера
        $channel->confirm_select(); // change channel mode

        $channel->queue_declare($queue, false, true, false, false);
        /****/

        $message = json_encode($incomingMessage);

        $msg = new AMQPMessage($message, ['delivery_mode' => 2]);

        $channel->basic_publish($msg, '', $queue);

        $channel->close();
        $connection->close();
    }

}

$worker = new CamundaConnector();
$worker->run();
