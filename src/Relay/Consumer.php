<?php

namespace SignalWire\Relay;

use Generator as Coroutine;
use SignalWire\Log;
use Recoil\Recoil;
use Recoil\React\ReactKernel;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as ReactFactory;

abstract class Consumer {
  /**
   * SignalWire Space Url
   * @var String
   */
  public $spaceUrl;

  /**
   * SignalWire project
   * @var String
   */
  public $project;

  /**
   * SignalWire token
   * @var String
   */
  public $token;

  protected $loop = null;
  protected $client = null;
  private $_kernel = null;

  function __construct() {
    if (!isset($this->project)) {
      throw new LogicException(get_class($this) . ' must have a $project.');
    }
    if (!isset($this->token)) {
      throw new LogicException(get_class($this) . ' must have a $token.');
    }
  }

  public function setup(): Coroutine {
    yield;
  }

  public function tearDown(): Coroutine {
    yield;
  }

  public final function run() {
    if (!($this->loop instanceof LoopInterface)) {
      $this->loop = ReactFactory::create();
    }
    $this->_kernel = ReactKernel::create($this->loop);
    $this->_kernel->execute([$this, '_init']);
    $this->loop->run();
    ReactKernel::start(function() {
      yield $this->tearDown();
    });
  }

  public function _init(): Coroutine {
    $this->client = new Client([
      'host' => $this->spaceUrl,
      'project' => $this->project,
      'token' => $this->token,
      'eventLoop' => yield \Recoil\Recoil::eventLoop()
    ]);

    $this->client->on('signalwire.error', function($error) {
      Log::error($error->getMessage());
    });

    $this->client->on('signalwire.ready', yield Recoil::callback(function($client) {
      try {
        yield $this->_registerCallingContexts();
        yield $this->setup();
      } catch (\Throwable $th) {
        Log::error($th->getMessage());
        throw $th;
      }
    }));

    yield $this->client->connect();
  }

  private function _registerCallingContexts(): Coroutine {
    if (!property_exists($this, 'contexts')) {
      return false;
    }
    if (!method_exists($this, 'onIncomingCall')) {
      throw new LogicException(get_class($this) . ' missing onIncomingCall() method to handle incoming calls.');
    }
    $promises = [];
    foreach ((array)$this->contexts as $context) {
      $promises[] = $this->client->calling->onInbound($context, yield Recoil::callback([$this, 'onIncomingCall']));
    }
    $results = yield $promises;
    foreach ($results as $res) {
      Log::info($res->message);
    }
    return $results;
  }
}