#!/usr/bin/php
<?php

/**
 * Camunda Connector
 *
 * BPM-to-RabbitMQ
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
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
    protected $lockDuration = CAMUNDA_CONNECTOR_LOCK_DURATION;

    /** @var string */
    protected $workerId;

    /** @var ExternalTaskService */
    protected $externalTaskService;

    /** @var json string */
    protected $incomingMessage;

    /** @var array **/
    protected $incomingParams = [
        'command' => [
            'name'      => 'command',
            'required'  => true,
            'default'   => null
        ],
        'queue' => [
            'name'      => 'queue',
            'required'  => true,
            'default'   => null
        ],
        'vhost' => [
            'name'      => 'vhost',
            'required'  => false,
            'default'   => RMQ_VHOST
        ],
        'retries' => [
            'name'      => 'retries',
            'required'  => false,
            'default'   => CAMUNDA_CONNECTOR_DEFAULT_RETRIES
        ],
        'retryTimeout' => [
            'name'      => 'retryTimeout',
            'required'  => false,
            'default'   => CAMUNDA_CONNECTOR_DEFAULT_RETRY_TIMEOUT
        ],
        'response_to' => [
            'name'      => 'response_to',
            'required'  => false,
            'default'   => null
        ],
        'response_command' => [
            'name'      => 'response_command',
            'required'  => false,
            'default'   => null
        ]
    ];

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {

        $this->initSignalHandler();
        $this->getOptions();
        $this->workerId = 'worker' . getmypid();
        $this->externalTaskService = new ExternalTaskService($this->camundaUrl);

        Logger::log('Waiting for task. To exit press CTRL+C', '-', '-','bpm-connector-in', 0);

        while (true) {
            // Quit on Ctrl+C
            pcntl_signal_dispatch();
            foreach ($this->fetchExternalTasks() as $externalTask) {
                $logMessage = sprintf(
                    "Fetched and locked from topic <%s> task <%s> of process <%s> process instance <%s>",
                    $externalTask->topicName,
                    $externalTask->id,
                    $externalTask->processDefinitionKey,
                    $externalTask->processInstanceId
                );
                Logger::log(
                    $logMessage,
                    'input',
                    '-',
                    'bpm-connector-in',
                    0
                );

                call_user_func([$this, $this->topicNameToMethodName($externalTask->topicName)], $externalTask);
            }
            usleep(CAMUNDA_TICK_TIMEOUT);
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
     * Get options
     */
    protected function getOptions(): void
    {
        $this->camundaUrl = sprintf(CAMUNDA_API_URL, CAMUNDA_API_LOGIN, CAMUNDA_API_PASS); // camunda api with basic auth
        $this->externalTaskTopic = CAMUNDA_CONNECTOR_TOPIC;

        $methodName = $this->topicNameToMethodName($this->externalTaskTopic);
        if (!method_exists($this, $methodName)) {
            fwrite(STDERR, "Error: Wrong value for --task-topic. Method $methodName does not exist.\n");
            exit(1);
        }
    }

    /**
     * Error: param not set
     *
     * @param $paramName
     */
    protected function paramNotSet($paramName, $externalTask): void
    {
        $logMessage = sprintf(
            "`%s` param not set from topic <%s> task <%s> of process <%s> process instance <%s> ",
            $paramName,
            $externalTask->topicName,
            $externalTask->id,
            $externalTask->processDefinitionKey,
            $externalTask->processInstanceId
        );
        Logger::log(
            $logMessage,
            '',
            '-',
            'bpm-connector-in',
            1
        );
        exit(1);
    }

    /**
     * If parameter is reserved:
     * Belong to $this->incomingParams or if is `message`
     *
     * @param string $key
     * @return bool
     */
    protected function paramIsReserved($key): bool
    {
        $isIncomingParam = array_key_exists($key, (array) $this->incomingParams);
        $isMessage = $key === 'message';

        return $isIncomingParam || $isMessage;
    }

    /**
     * Fetch and assign Camunda Unsafe params
     *
     * @param object $externalTask
     * @return array
     */
    protected function assignCamundaUnsafeParams($externalTask): array
    {
        foreach ($this->incomingParams as $key => $param) {

            // Check isset param
            if(!isset($externalTask->variables->{$param['name']})) {
                // If param is required
                if($param['required']) {
                    $this->paramNotSet($param['name'], $externalTask); // error & exit
                } else {
                    // If default not null
                    if($this->incomingParams[$key]['default'] !== null) {
                        $this->incomingParams[$key]['value'] = $this->incomingParams[$key]['default'];
                    }
                }
            } else {
                // Assign param
                $this->incomingParams[$key]['value'] = $externalTask->variables->{$param['name']}->value;
            }
        }

        return $this->incomingParams;
    }

    /**
     * Fetch and assign Camunda Safe params
     *
     * @param object $externalTask
     * @return array
     */
    protected function assignCamundaSafeParams($externalTask): array
    {
        // Add `data.parameters` if is not set
        if(!isset($this->incomingMessage['data']['parameters'])) {
            $this->incomingMessage['data']['parameters'] = [];
        }

        // Set safe variables in `data.parameters`
        foreach((array)$externalTask->variables as $key => $variable) {
            // If parameter is not reserved
            if(!$this->paramIsReserved($key)) {
                $this->incomingMessage['data']['parameters'][$key] = $variable->value;
            }
        }

        return $this->incomingMessage;
    }

    /**
     * @param object $externalTask
     * @return array
     */
    public function setHeadersFromIncomingParams($externalTask): array
    {
        // Add external task id, process instance id, worker id in headers
        $camundaHeaders = [
            'camundaExternalTaskId'    => $externalTask->id,
            'camundaProcessInstanceId' => $externalTask->processInstanceId,
            'camundaWorkerId'          => $this->workerId,
            'camundaRetries'           => $externalTask->retries ?? $this->incomingParams['retries']['value'],
            'camundaRetryTimeout'      => $this->incomingParams['retryTimeout']['value']
        ];
        $this->incomingMessage['headers'] = array_merge($this->incomingMessage['headers'], $camundaHeaders);

        // Add `command`, `queue` and `vhost` in headers
        $this->incomingMessage['headers']['command'] = $this->incomingParams['command']['value'];
        $this->incomingMessage['headers']['queue'] = $this->incomingParams['queue']['value'];
        $this->incomingMessage['headers']['vhost'] = $this->incomingParams['vhost']['value'];

        // Add `response_to` and `response_command`
        if(isset($this->incomingParams['response_to']['value'])) {
            $this->incomingMessage['headers']['response_to'] = $this->incomingParams['response_to']['value'];
            if(isset($this->incomingParams['response_command']['value'])) {
                $this->incomingMessage['headers']['response_command'] = $this->incomingParams['response_command']['value'];
            }
        }

        // return headers only
        return $this->incomingMessage['headers'];
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
        // Fetch and assign Camunda unsafe params
        $this->assignCamundaUnsafeParams($externalTask);

        // Incoming message from rabbit mq
        $incomingMessageAsString = $externalTask->variables->message->value ?? json_encode(['data'=>'', 'headers'=>'']);
        $this->incomingMessage = json_decode($incomingMessageAsString, true);

        // Fetch headers params from incomingParams and put in message.headers
        $this->setHeadersFromIncomingParams($externalTask);

        // Fetch and assign Camunda safe params to message.data.parameters
        $this->assignCamundaSafeParams($externalTask);

        // Open connection
        $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, $this->incomingParams['vhost']['value'], false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
        $channel = $connection->channel();
        $channel->confirm_select(); // change channel mode

        // Send message
        $message = json_encode($this->incomingMessage);
        $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
        $channel->basic_publish($msg, '', $this->incomingParams['queue']['value']);

        // For test
        // $channel->basic_publish($msg, '', RMQ_QUEUE_ERR);

        // Close channel
        $channel->close();
        $connection->close();
    }

}

$worker = new CamundaConnector();
$worker->run();
