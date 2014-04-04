<?php

namespace GuzzleHttp\Command\Event;

use GuzzleHttp\Command\CanceledResponse;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\ServiceClientInterface;

/**
 * Utility class used to wrap HTTP events with client events.
 */
class CommandEvents
{
    /**
     * Handles the workflow of a command before it is sent.
     *
     * This includes preparing a request for the command, hooking the command
     * event system up to the request's event system, and returning the
     * prepared request.
     *
     * @param CommandInterface       $cmd    Command to prepare
     * @param ServiceClientInterface $client Client that executes the command
     *
     * @return PrepareEvent returns the PrepareEvent. You can use this to see
     *     if the event was intercepted with a result, or to grab the request
     *     that was prepared for the event.
     *
     * @throws \RuntimeException
     */
    public static function prepare(
        CommandInterface $cmd,
        ServiceClientInterface $client
    ) {
        $ev = new PrepareEvent($cmd, $client);
        $cmd->getEmitter()->emit('prepare', $ev);
        $req = $ev->getRequest();
        $stopped = $ev->isPropagationStopped();

        if (!$req && !$stopped) {
            throw new \RuntimeException('No request was prepared for the'
                . ' command and no result was added to intercept the event. One'
                . ' of the listeners must set a request in the prepare event.');
        }

        if ($stopped) {
            // Event was intercepted with a result, so emit the process event.
            self::process($cmd, $client, $req, null, $ev->getResult());
        } elseif ($req) {
            self::injectErrorHandler($cmd, $client, $req);
        }

        return $ev;
    }

    /**
     * Handles the processing workflow of a command after it has been sent and
     * a response has been received.
     *
     * @param CommandInterface       $command  Command that was executed
     * @param ServiceClientInterface $client   Client that sent the command
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface      $response Response that was received
     * @param mixed                  $result   Specify the result if available
     *
     * @return mixed|null Returns the result of the command
     */
    public static function process(
        CommandInterface $command,
        ServiceClientInterface $client,
        RequestInterface $request = null,
        ResponseInterface $response = null,
        $result = null
    ) {
        // Handle when an error event is intercepted before sending a request.
        if ($response instanceof CanceledResponse) {
            return self::getCanceledResult($command);
        }

        $e = new ProcessEvent($command, $client, $request, $response, $result);
        $command->getEmitter()->emit('process', $e);

        // Track the result of a command using the request if needed.
        if (!$response && $request) {
            $command->getConfig()['__result'] = $e->getResult();
        }

        return $e->getResult();
    }

    /**
     * Wrap HTTP level errors with command level errors.
     *
     * @param CommandInterface       $cmd     Command to modify
     * @param ServiceClientInterface $client  Client associated with the command
     * @param RequestInterface       $request Prepared request for the command
     */
    private static function injectErrorHandler(
        CommandInterface $cmd,
        ServiceClientInterface $client,
        RequestInterface $request
    ) {
        $listener = function (ErrorEvent $re) use ($cmd, $client) {
            $re->stopPropagation();
            $ce = new CommandErrorEvent($cmd, $client, $re);
            $cmd->getEmitter()->emit('error', $ce);

            if (!$ce->isPropagationStopped()) {
                self::throwErrorException($cmd, $client, $re);
            }

            // Process the command if the error event was intercepted.
            self::addCanceledResponse($ce);
            self::process($cmd, $client, $re->getRequest(), null, $ce->getResult());
        };

        $request->getEmitter()->on('error', $listener, RequestEvents::LATE);
    }

    private static function throwErrorException(
        CommandInterface $cmd,
        ServiceClientInterface $client,
        ErrorEvent $e
    ) {
        $className = 'GuzzleHttp\\Command\\Exception\\CommandException';

        // If a response was received, then throw a specific exception.
        if ($res = $e->getResponse()) {
            $statusCode = (string) $res->getStatusCode();
            if ($statusCode[0] == '4') {
                $className = 'GuzzleHttp\\Command\\Exception\\CommandClientException';
            } elseif ($statusCode[0] == '5') {
                $className = 'GuzzleHttp\\Command\\Exception\\CommandServerException';
            }
        }

        $ex = $e->getException();
        $m = "Error executing command: " . $ex->getMessage();

        throw new $className($m, $client, $cmd, $e->getRequest(), $res, $ex);
    }

    /**
     * If a command error event was intercepted, then the request event
     * lifecycle needs to be intercepted as well with a CanceledResponse
     * object and the request complete event must be suppressed.
     */
    private static function addCanceledResponse(CommandErrorEvent $event)
    {
        if (!$event->getRequestErrorEvent()->getResponse()) {
            $fn = function ($e) { $e->stopPropagation(); };
            $event->getRequest()->getEmitter()->once('complete', $fn, 'first');
            $event->getRequestErrorEvent()->intercept(new CanceledResponse());
        }
    }

    private static function getCanceledResult(CommandInterface $command)
    {
        $config = $command->getConfig();
        $result = $config['__result'];
        unset($config['__result']);

        return $result;
    }
}
