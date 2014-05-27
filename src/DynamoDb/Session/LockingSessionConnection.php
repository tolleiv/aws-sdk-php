<?php
namespace Aws\DynamoDb\Session;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\DynamoDbException;

/**
 * The locking connection adds locking logic to the read operation.
 */
class LockingSessionConnection extends StandardSessionConnection
{
    public function __construct(DynamoDbClient $client, array $config)
    {
        parent::__construct($client, $config + [
            'max_lock_wait_time'       => 10,
            'min_lock_retry_microtime' => 10000,
            'max_lock_retry_microtime' => 50000,
        ]);
    }

    /**
     * {@inheritdoc}
     * Retries the request until the lock can be acquired
     */
    public function read($id)
    {
        // Create the params for the UpdateItem operation so that a lock can be
        // set and item returned (via ReturnValues) in a one, atomic operation.
        $params = [
            'TableName'        => $this->config['table_name'],
            'Key'              => $this->formatKey($id),
            'Expected'         => ['lock' => ['Exists' => false]],
            'AttributeUpdates' => ['lock' => ['Value' => ['N' => '1']]],
            'ReturnValues'     => 'ALL_NEW',
        ];

        // Acquire the lock and fetch the item data
        $timeout  = time() + $this->config['max_lock_wait_time'];
        $this->client->waitUntil(
            function () use (&$item, $params, $timeout) {
                $item = [];
                try {
                    $result = $this->client->updateItem($params);
                    if (isset($result['Attributes'])) {
                        foreach ($result['Attributes'] as $key => $value) {
                            $item[$key] = current($value);
                        }
                    }
                } catch (DynamoDbException $e) {
                    if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException'
                        && time() < $timeout
                    ) {
                        return false;
                    }
                }
                return true;
            },
            [
                'interval' => function () {
                    return pow(10, -6) * rand(
                        $this->config['min_lock_retry_time'],
                        $this->config['max_lock_retry_time']
                    );
                }
            ]
        );

        return $item;
    }
}