# bpm-connector
Connector BPM-to-RabbitMQ-to-BPM for Camunda BPM on PHP. Using for transmitting variables from External Tasks to another external workers over AMQP and transmitting results from external workers to External Tasks.

## connector.php - BPM-to-RabbitMQ connector
Connector is a external task in Camunda BPM, which listen task job for specified topic.
When a task arrives, connector fetch and lock her on specified lock duration time.
After that his redirects it to the specified queue in Rabbit MQ.

_run microservice in command promt mode:_
```bash
php connector.php
```

## connector-out.php - RabbitMQ-to-BPM connector
Listens to the Rabbit MQ queue with completed task reports for Camunda BPM,
reports to Camunda BPM about the completed task and closes it.

_run microservice in command promt mode:_
```bash
php connector-out.php
```

## consumer.php - worker for test
Worker which imitates the execution of an external task.

_run microservice in command promt mode:_
```bash
php consumer.php
```

##Configuration constants

- CAMUNDA_API_URL=https://bpm.kubia.dev/engine-rest
- CAMUNDA_CONNECTOR_TOPIC=connector
- CAMUNDA_CONNECTOR_LOCK_DURATION=36000000
- RMQ_HOST=10.8.0.58
- RMQ_PORT=5672
- RMQ_VHOST=quancy.com.sg
- RMQ_USER=`<secret>`
- RMQ_PASS=`<secret>`
- RMQ_QUEUE_IN=bpm_in
- RMQ_QUEUE_OUT=bpm_out

##Start process in Camunda BPM

POST request
```
POST https://bpm.kubia.dev/engine-rest/process-definition/key/process-connector/start
```

json payload
```json
{
  "variables": {
    "message": {
      "value": "{\"data\":{\"user\":{\"first_name\":\"John\",\"last_name\":\"Doe\"},\"account\":{\"number\":\"702-0124511\"},\"date_start\":\"2019-09-14\",\"date_end\":\"2019-10-15\"},\"headers\":{\"command\":\"createTransactionsReport\"}}",
      "type": "String"
    }
  }
}
```
