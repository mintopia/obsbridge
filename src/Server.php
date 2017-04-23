<?php
	namespace Mintopia\OBSBridge;
    
    use Monolog\Logger;
    use Monolog\Handler\StreamHandler;

	class Server {
        protected $loop;
        protected $loggers;
        protected $obs;
        protected $rabbitmq;
        
        public function init()
        {
            $this->loop = \React\EventLoop\Factory::create();
            $this->obs = new Obs($this, 'ws://127.0.0.1:4444');
            $this->rabbitmq = new RabbitMQ($this, $this->getRabbitMQConfig());
            
            $this->loop->run();
        }
        
        public function getLoop()
        {
            return $this->loop;
        }
        
        public function getLogger($name)
        {
            if (!isset($this->loggers[$name])) {
                $this->loggers[$name] = $this->createLogger($name);
            }
            return $this->loggers[$name];
        }
        
        public function broadcastMessage($message)
        {
            $this->rabbitmq->sendBroadcast($message);
        }
        
        public function getOBS()
        {
            return $this->obs;
        }
        
        protected function createLogger($name)
        {
            $logger = new Logger($name);
            $handler = new StreamHandler('php://stdout', Logger::INFO);
            $logger->pushHandler($handler);
            return $logger;
        }
        
        protected function getRabbitMQConfig()
        {
            return [
                'host' => '127.0.0.1',
                'port' => 5672,
                'vhost' => '/',
                'user' => 'guest',
                'password' => 'guest'
            ];
        }
	}