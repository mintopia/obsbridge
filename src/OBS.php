<?php
namespace Mintopia\OBSBridge;

use Ramsey\Uuid\Uuid;

class OBS
{
    protected $server;
    protected $log;
    protected $loop;
    protected $url;
    
    protected $socket;
    protected $connected = false;
    protected $responses = [];

    public function __construct(Server $server, $url)
    {
        $this->server = $server;
        $this->loop = $server->getLoop();
        $this->log = $server->getLogger('OBS');
        $this->url = $url;
        
        $this->run();
    }
    
    public function run()
    {
        $this->connect();
        $this->loop->addPeriodicTimer(5, function() {
            $this->connect();
        });
    }

    public function connect()
    {
        if ($this->connected) {
            return;
        }
        
        $this->log->info("Connecting to {$this->url}");
        
        $connector = new \Ratchet\Client\Connector($this->loop);
        $connector($this->url)->then(function (\Ratchet\Client\WebSocket $conn) {
            $this->log->info("Connected");
            $this->connected = true;
            $this->socket = $conn;
            $this->socket->on('message', function (\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn) {
                $this->handleMessage($msg);
            });
            $this->socket->on('close', function($code = null, $reason = null) {
                $this->close($code, $reason);
            });
            $this->getCurrentScene();
        }, function (\Exception $e) {
            $this->log->error("Failed to connect: {$e->getMessage()}");
            $this->connected = false;
        });
    }

    protected function handleMessage($message)
    {
        $json = json_decode((string) $message);
        if (!$json) {
            return;
        }
        if (property_exists($json, 'message-id')) {
            $messageId = $json->{'message-id'};
            // Handle response
            if (!isset($this->responses[$messageId])) {
                return;
            }
            
            $request = $this->responses[$messageId];
            unset($this->responses[$messageId]);
            
            $this->log->debug("Recieved response for {$messageId} ($request->command}");
            
            $request->deferred->resolve($json);
            return;
        }

        $type = $json->{'update-type'};

        $this->log->debug("Recieved: {$type}");
        if (method_exists($this, 'handle' . $type)) {
            $this->{'handle' . $type}($json);
        }
    }

    public function close($code = null, $reason = null)
    {
        $this->log->info("Closed: {$code} {$reason}");
        $this->connected = false;
    }
    
    protected function getMessageId()
    {
        return (string) Uuid::uuid4();
    }
    
    protected function sendWithResponse($command, $data = null)
    {
        $deferred = new \React\Promise\Deferred;
        $messageId = $this->getMessageId();
        $this->responses[$messageId] = (object) [
            'messageId' => $messageId,
            'deferred' => $deferred,
            'command' => $command
        ];
        $this->send($messageId, $command, $data);
        
        return $deferred->promise();
    }

    public function send($messageId, $command, $data = null)
    {
        if (!$this->socket) {
            return;
        }
        
        if (is_array($data)) {
            $data = (object) $data;
        }
        
        if (!$data) {
            $data = new \stdClass;
        }

        $data->{'message-id'} = $messageId;
        $data->{'request-type'} = $command;

        return $this->socket->send(json_encode($data));
    }
    
    public function getCurrentScene()
    {
        $this->sendWithResponse('GetCurrentScene')->then(function ($response) {
            $this->log->info("Current Scene: {$response->name}");
            $message = (object) [
                'type' => 'CurrentScene',
                'data' => (object) [
                    'scene' => $response->name
                ]
            ];
            $this->server->broadcastMessage($message);
        });
    }
    
    public function switchScene($newScene)
    {
        $params = (object) [
            'scene-name' => $newScene
        ];
        $this->send(Uuid::uuid4(), 'SetCurrentScene', $params);
    }
    
    protected function handleStreamStatus($data)
    {
        $message = (object) [
            'type' => 'StreamStatus',
            'data' => $data
        ];
        $this->server->broadcastMessage($message);
    }
    
    protected function handleSwitchScenes($data)
    {
        $scene = $data->{'scene-name'};
        $this->log->info("Current Scene: {$scene}");
        $message = (object) [
            'type' => 'CurrentScene',
            'data' => (object) [
                'scene' => $scene
            ]
        ];
        $this->server->broadcastMessage($message);
    }
}
