<?php

namespace Prwnr\Streamer\Contracts\Errors;

use Exception;
use Prwnr\Streamer\Contracts\MessageReceiver;
use Prwnr\Streamer\Errors\FailedMessage;
use Prwnr\Streamer\EventDispatcher\ReceivedMessage;

interface ErrorHandler
{
    /**
     * Stores failed message information in a list for later retry attempt.
     *
     * @param  ReceivedMessage  $message
     * @param  MessageReceiver  $receiver
     * @param  Exception  $e
     * @return mixed
     */
    public function handle(ReceivedMessage $message, MessageReceiver $receiver, Exception $e): void;

    /**
     * Looks up message on a stream and attempts to retry it with given receiver.
     *
     * @param  FailedMessage  $message
     */
    public function retry(FailedMessage $message): void;
}