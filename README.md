#  BPM AMQP Connector for External Tasks 
Connector BPM-to-RabbitMQ-to-BPM for Camunda BPM on PHP. Using for transmitting variables from External Tasks to another external workers over AMQP and transmitting results from external workers to External Tasks.

## Docker images
| Docker image | Version tag | Date of build |
| --- | --- | --- |
| docker.quancy.com.sg/bpm-connector | latest | 2019-12-13 |

## Queues
- Incoming queue: `bpm_in` (can be changed, set in the connector diagram as input parameter)
- Outgoing queue: `bpm_out`

## Requirements
- php7.2-cli
- php7.2-bcmath
- php-mbstring
- php-amqp
- composer
- supervisor

## Configuration constants
- CAMUNDA_API_LOGIN=`<secret>`
- CAMUNDA_API_PASS=`<secret>`
- CAMUNDA_API_URL=https://%s:%s@bpm.kubia.dev/engine-rest
- CAMUNDA_CONNECTOR_TOPIC=connector
- CAMUNDA_CONNECTOR_LOCK_DURATION=36000000
- CAMUNDA_CONNECTOR_DEFAULT_RETRIES=0
- CAMUNDA_CONNECTOR_DEFAULT_RETRY_TIMEOUT=1000
- RMQ_HOST=10.8.0.58
- RMQ_PORT=5672
- RMQ_VHOST=quancy.com.sg
- RMQ_USER=`<secret>`
- RMQ_PASS=`<secret>`
- RMQ_QUEUE_IN=bpm_in
- RMQ_QUEUE_OUT=bpm_out

## Input Parameters for connector from Camunda diagram
- queue
- retries
- retryTimeout

## Preparing
Download and install [Camunda Modeler](https://camunda.com/download/modeler/).

## Installation
```
git clone https://gitlab.com/quancy-core/bpm-connector.git
```

## Start
1. Run docker container
2. Deploy diagram
3. Run test microservice `consumer.php` in command promt mode
4. Run process instance

_That deploy buisness process you must open `/models/connector.bpmn` in [Camunda Modeler](https://camunda.com/download/modeler/) and deploy it to Communda server._

## Build and run as docker container
```
docker-compose build
docker-compose up
```

## Build and run as docker container daemon
```
docker-compose build
docker-compose up -d
```

## Stop docker container daemon
```
docker-compose down
```

## BPM-to-RabbitMQ connector `connector-in.php`
Connector is a external task in Camunda BPM, which listen task job for specified topic.
When a task arrives, connector fetch and lock her on specified lock duration time.
After that his redirects it to the specified queue in Rabbit MQ.

_run microservice in command promt mode:_
```bash
php connector-in.php
```

## RabbitMQ-to-BPM connector `connector-out.php`
Listens to the Rabbit MQ queue with completed task reports for Camunda BPM,
reports to Camunda BPM about the completed task and closes it.

_run microservice in command promt mode:_
```bash
php connector-out.php
```

## Worker for test `consumer.php`
Worker which imitates the execution of an external task.

_run microservice in command promt mode:_
```bash
php consumer.php
```

## Run process in Camunda BPM
To run process instance you must create POST request with basic auth and valid payload as json.

```
POST https://bpm.kubia.dev/engine-rest/process-definition/key/process-connector/start
```

json payload
```json
{
  "variables": {
    "message": {
      "value": {
        "data": {
          "user": {
            "first_name": "John",
            "last_name": "Doe"
          },
          "account": {
            "number": "702-0124511"
          },
          "date_start": "2019-09-14",
          "date_end": "2019-10-15"
        },
        "headers": {
          "command": "createTransactionsReport",
          "camundaProcessKey": "process-connector"
        }
      },
      "type": "Json"
    }
  }
}
```
