# Changelog

Connector BPM-to-RabbitMQ-to-BPM for Camunda BPM on PHP.
Using for transmitting variables from External Tasks to another external workers over AMQP and transmitting results from external workers to External Tasks.


## [Unreleased]

### Planned
- Nothing

## [0.3] - 2019-12-17

### Added
- Set non-blocking mode of receiving messages for Rabbit MQ connection
- Set QoS parameter for channel: one message per one loop for Rabbit MQ connection
- Set manual acknowledge for received message for Rabbit MQ connection
- Remove queue declare for Rabbit MQ connection

## [0.2] - 2019-12-17

### Added

- Added basic auth for API Camunda BPM in config
- Added validate message headers in connector-out 
- Added validate Camunda external params in connector-in

## [0.1] - 2019-12-13

### Added

- First worked version on connector
- Docker environment
- Worker for test connector
- README and CHANGELOG

[unreleased]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.3
[0.3]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.3
[0.2]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.2
[0.1]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.1
