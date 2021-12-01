<?php

namespace Ensi\LaravelPhpRdKafkaConsumer\Commands;

use Ensi\LaravelPhpRdKafkaConsumer\ConsumerOptions;
use Ensi\LaravelPhpRdKafkaConsumer\HighLevelConsumer;
use Ensi\LaravelPhpRdKafkaConsumer\ProcessorData;
use Illuminate\Console\Command;
use Throwable;

class KafkaConsumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kafka:consume
                            {topic : The name of the topic}
                            {consumer=default : The name of the consumer}
                            {--max-events=0 : The number of events to consume before stopping}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--once : Only process the next event in the topic}
                            ';

    /**
     * The console command description.
     */
    protected $description = 'Consume concrete topic';

    /**
     * Execute the console command.
     */
    public function handle(HighLevelConsumer $highLevelConsumer): int
    {
        $topic = $this->argument('topic');
        $consumer = $this->argument('consumer');
        $availableConsumers = array_keys(config('kafka.consumers', []));

        if (!in_array($consumer, $availableConsumers)) {
            $this->error("Unknown consumer \"$consumer\"");
            $this->line('Available consumers are: "' . implode(', ', $availableConsumers) . '" and can be found in /config/kafka.php');

            return 1;
        }

        $processorData = $this->findMatchedProcessor($topic, $consumer);
        if (is_null($processorData)) {
            $this->error("Processor for topic \"$topic\" and consumer \"$consumer\" is not found");
            $this->line('Processors are set in /config/kafka-consumers.php');

            return 1;
        }

        if (!class_exists($processorData->class)) {
            $this->error("Processor class \"$processorData->class\" is not found");
            $this->line('Processors are set in /config/kafka-consumers.php');

            return 1;
        }

        if (!$processorData->hasValidType()) {
            $this->error("Invalid processor type \"$processorData->type\", supported types are: " . implode(',', $processorData->getSupportedTypes()));

            return 1;
        }

        $consumerPackageOptions = config('kafka-consumer.consumer_options.'. $consumer, []);
        $consumerOptions = new ConsumerOptions(
            consumeTimeout: $consumerPackageOptions['consume_timeout'] ?? $processorData->consumeTimeout,
            maxEvents: $this->option('once') ? 1 : (int) $this->option('max-events'),
            maxTime: (int) $this->option('max-time'),
            middleware: $this->collectMiddleware($consumerPackageOptions['middleware'] ?? []),
        );

        $this->info("Start listenning to topic: \"$topic\", consumer \"$consumer\"");

        try {
            $highLevelConsumer
                ->for($consumer)
                ->listen($topic, $processorData, $consumerOptions);
        } catch (Throwable $e) {
            $this->error('An error occurred while listening to the topic: '. $e->getMessage(). ' '. $e->getFile() . '::' . $e->getLine());

            return 1;
        }

        return 0;
    }

    protected function findMatchedProcessor(string $topic, string $consumer): ?ProcessorData
    {
        foreach (config('kafka-consumer.processors', []) as $processor) {
            if (
                (empty($processor['topic']) || $processor['topic'] === $topic)
                && (empty($processor['consumer']) || $processor['consumer'] === $consumer)
                ) {
                return new ProcessorData(
                    class: $processor['class'],
                    topic: $processor['topic'] ?? null,
                    consumer: $processor['consumer'] ?? null,
                    type: $processor['type'] ?? 'action',
                    queue: $processor['queue'] ?? false,
                    consumeTimeout: $processor['consume_timeout'] ?? 20000,
                );
            }
        }

        return null;
    }

    protected function collectMiddleware(array $processorMiddleware): array
    {
        return array_unique(
            array_merge(
                config('kafka-consumer.global_middleware', []),
                $processorMiddleware
            )
        );
    }
}
