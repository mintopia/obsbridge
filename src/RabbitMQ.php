<?php
    namespace Mintopia\OBSBridge;
    
    use Bunny\Async\Client;
    use Bunny\Channel;
    use Bunny\Message;
    
    class RabbitMQ
    {
        protected $server;
        protected $loop;
        protected $log;
        protected $config;
        
        protected $connected = false;
        
        protected $eventChannel;
        
        public function __construct(Server $server, $config)
        {
            $this->server = $server;
            $this->loop = $server->getLoop();
            $this->log = $server->getLogger('RabbitMQ');
            $this->config = $config;
            
            $this->run();
        }
        
        public function run()
        {
            $this->connect();
            $this->loop->addPeriodicTimer(5, function () {
                $this->connect();
            });
        }
        
        public function connect()
        {
            if ($this->connected) {
                return;
            }
            
            $this->log->info("Connecting to {$this->config['host']}");
            
            $client = new Client($this->loop, $this->config);
            $client->connect()->then(function (Client $client) {
                $this->connected = true;
                $this->log->info('Connected');
                return $client->channel();
            }, function ($e) {
                $this->connected = false;
                $this->log->error("Connection Failed: {$e->getMessage()}");
            })->then(function (Channel $channel) {
                $channel->exchangeDeclare('obs_events', 'fanout')->then(function () use ($channel) {
                    $this->log->info('Event channel connected');
                    $this->eventChannel = $channel;
                });
                
                $channel->queueDeclare('obs_actions', false, true, false, false)->then(function() use ($channel) {
                    $this->log->info('Action queue declared');
                    return $channel;
                })->then(function (Channel $channel) {
                    $this->log->info('Ready to consume');
                    $channel->consume(function(Message $message, Channel $channel, Client $client) {
                        try {
                            $this->handleMessage($message);
                            $result = true;
                        } catch (\Exception $e) {
                            $result = false;
                        }
                        
                        if ($result) {
                            $channel->ack($message);
                        } else {
                            $channel->nack($message);
                        }
                    });
                });
            });
        }
        
        public function reconnect(\Exception $e)
        {
            $this->log->error($e->getMessage());
            $this->connected = false;
        }
        
        public function sendBroadcast($data)
        {
            
            if (!$this->eventChannel) {
                return;
            }
            
            if (is_object($data)) {
                $data = json_encode($data);
            }
            
            $this->log->debug("Sending broadcast");
            $this->eventChannel->publish($data, [], 'obs_events');
        }
        
        public function handleMessage($message) {
            $json = json_decode($message->content);
            if (!$json || !property_exists($json, 'action')) {
                return;
            }
            
            $methodName = 'process' . $json->action;
            if (method_exists($this, $methodName)) {
                $this->{$methodName}($json);
            }
        }
        
        public function processGetScene($data)
        {
            $this->log->info("Current scene requested");
            $this->server->getOBS()->getCurrentScene();
        }
        
        public function processSwitchScene($data)
        {
            $this->log->notice("Switch scene to {$data->scene}");
            $this->server->getOBS()->switchScene($data->scene);
        }
    }