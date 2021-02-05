<?php

namespace Tests;

use Exception;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Illuminate\Support\Arr;
use Prwnr\Streamer\Concerns\ConnectsWithRedis;
use Prwnr\Streamer\EventDispatcher\Message;
use Prwnr\Streamer\EventDispatcher\ReceivedMessage;
use Prwnr\Streamer\MessagesErrorHandler;
use Prwnr\Streamer\Stream;
use Tests\Stubs\LocalListener;

class MessagesErrorHandlerTest extends TestCase
{
    use InteractsWithRedis;
    use ConnectsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRedis();
        $this->redis['phpredis']->connection()->flushall();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis['phpredis']->connection()->flushall();
        $this->tearDownRedis();
    }

    public function test_stores_failed_message_information(): void
    {
        $handler = new MessagesErrorHandler();
        $message = new ReceivedMessage('123', [
            'name' => 'foo.bar',
            'data' => json_encode('payload')
        ]);
        $listener = new LocalListener();
        $e = new Exception('error');
        $handler->handle($message, $listener, $e);
        $failed = $this->redis()->sMembers(MessagesErrorHandler::ERRORS_LIST);

        $this->assertNotEmpty($failed);
        $this->assertCount(1, $failed);

        $actual = json_decode($failed[0], true);
        $this->assertEquals([
            'id' => $message->getId(),
            'stream' => 'foo.bar',
            'receiver' => LocalListener::class,
            'error' => 'error',
        ], $actual);
    }

    public function test_lists_all_failed_messages_info(): void
    {
        $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);
        $this->failFakeMessage('other.bar', '321', ['payload' => 321]);
        $this->failFakeMessage('some.bar', '456', ['payload' => 456]);

        $handler = new MessagesErrorHandler();
        $actual = $handler->list();

        $this->assertCount(3, $actual);
        $this->assertEquals([
            'id' => '123',
            'stream' => 'foo.bar',
            'receiver' => LocalListener::class,
            'error' => 'error',
        ], Arr::first($actual, static function ($item) {
            return $item['id'] === '123';
        }));
        $this->assertEquals([
            'id' => '321',
            'stream' => 'other.bar',
            'receiver' => LocalListener::class,
            'error' => 'error',
        ], Arr::first($actual, static function ($item) {
            return $item['id'] === '321';
        }));
        $this->assertEquals([
            'id' => '456',
            'stream' => 'some.bar',
            'receiver' => LocalListener::class,
            'error' => 'error',
        ], Arr::first($actual, static function ($item) {
            return $item['id'] === '456';
        }));
    }

    public function test_retries_failed_message(): void
    {
        $message = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($message) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $message->getId()
                    && $arg->getContent() && $message->getContent();
            })
            ->once()
            ->andReturn();

        $handler = new MessagesErrorHandler();
        $handler->retry(new Stream('foo.bar'), '123', LocalListener::class);
    }

    public function test_retries_multiple_failed_messages(): void
    {
        $firstMessage = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);
        $secondMessage = $this->failFakeMessage('foo.bar', '345', ['payload' => 'foobar']);

        $this->assertEquals(2, $this->redis()->sCard(MessagesErrorHandler::ERRORS_LIST));

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($firstMessage) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $firstMessage->getId()
                    && $arg->getContent() && $firstMessage->getContent();
            })
            ->once()
            ->andReturn();

        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($secondMessage) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $secondMessage->getId()
                    && $arg->getContent() && $secondMessage->getContent();
            })
            ->once()
            ->andReturn();

        $handler = new MessagesErrorHandler();
        $handler->retryAll();

        $this->assertEquals(0, $this->redis()->sCard(MessagesErrorHandler::ERRORS_LIST));
    }

    public function test_wont_retry_message_when_receiver_doest_not_exists(): void
    {
        $listener = $this->mock(LocalListener::class);
        $listener->shouldNotHaveBeenCalled();

        $handler = new MessagesErrorHandler();
        $handler->retry(new Stream('foo.bar'), '123', 'not a class');
    }

    public function test_wont_retry_message_when_it_doest_not_exists(): void
    {
        $listener = $this->mock(LocalListener::class);
        $listener->shouldNotHaveBeenCalled();

        $handler = new MessagesErrorHandler();
        $handler->retry(new Stream('foo.bar'), '123', LocalListener::class);
    }

    public function test_handles_failed_message_and_puts_it_back_when_it_fails_again(): void
    {
        $message = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($message) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $message->getId()
                    && $arg->getContent() && $message->getContent();
            })
            ->once()
            ->andThrow(Exception::class);

        $handler = new MessagesErrorHandler();
        $handler->retryAll();

        $this->assertEquals(1, $this->redis()->sCard(MessagesErrorHandler::ERRORS_LIST));
    }

    protected function failFakeMessage(string $stream, string $id, array $data): ReceivedMessage
    {
        $handler = new MessagesErrorHandler();
        $message = new ReceivedMessage($id, [
            'name' => $stream,
            'data' => json_encode($data)
        ]);
        $listener = new LocalListener();
        $e = new Exception('error');
        $handler->handle($message, $listener, $e);

        $meta = [
            '_id' => $id,
            'name' => $stream,
            'domain' => 'test',
        ];
        $stream = new Stream($stream);
        $stream->add(new Message($meta, $data), $id);

        return $message;
    }
}