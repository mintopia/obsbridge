# RabbitMQ to OBS Websocket Bridge

This small application is a proof-of-concept for bridging OBS Studio's
Websocket plugin to a RabbitMQ queue. It allows you to send command
over a RabbitMQ direct queue. Responses are sent back over a fanout
exchange.

The current stream status and any changes to the live scene are also
pushed out over the fanout exchange.

## Author

Jessica Smith - [jess@mintopia.net](mailto:jess@mintopia.net) - [http://mintopia.net](http://mintopia.net)

## License

OBS Bridge is licensed under the MIT License - see LICENSE.md for details.